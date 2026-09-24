<?php

declare(strict_types=1);

namespace Pushery\Billing\Consumer;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Pushery\Billing\Contracts\ReadsSubscriptionPayments;
use Pushery\Billing\Contracts\SubscriptionActions;
use Pushery\Billing\Enums\RefundKind;
use Pushery\Billing\Exceptions\CancellationUnavailable;
use Pushery\Billing\Exceptions\EndInsidePeriodIsFinal;
use Pushery\Billing\Exceptions\SubscriptionWithdrawalUnavailable;
use Pushery\Billing\Invoicing\ProratedTermRefund;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\Support\BillingAdmin;
use Pushery\Billing\ValueObjects\CancellationSettlement;
use Pushery\Billing\ValueObjects\MerchantScope;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\SubscriptionPeriodPayment;

/**
 * A subscription ends at a moment inside the period it was paid for, and the rest of that period goes back.
 *
 * ## The case this exists for
 *
 * A consumer contract that renewed tacitly may be canceled at any time at a month's notice, and on a yearly
 * subscription that end falls inside a paid year. `cancel()` ends at the period end and held the customer to the
 * end of the year, up to eleven months too long, and what they prepaid for the time after the end is theirs.
 * {@see GermanNoticePeriod} says when such a contract ends; this class ends the subscription there and pays the rest
 * back.
 *
 * ## Ended first, settled second
 *
 * The same order as a subscription withdrawal, for the same reason: should anything fail between the two, the owner
 * holds a contract that ends when it should, and the refund is one call away, through `BillingAdmin::refund()` with
 * the reference the settlement names. The other order would leave a refunded subscription running to the end of the
 * year it no longer paid for.
 *
 * ## The amount
 *
 * The payment behind the period in progress, read through the driver's {@see ReadsSubscriptionPayments}, split at
 * the end in whole days by {@see ProratedTermRefund}. The day the end falls on counts as not provided, the buyer's
 * side of the rounding, as it is for a withdrawal. The minor unit that does not divide stays with the part that was
 * provided, which is the direction the package already decided for an uneven split.
 *
 * A driver that collects a period at its end has no payment for the period in progress. The subscription then ends
 * at the moment asked for and nothing goes back, because nothing was paid.
 *
 * ## The same refund as every other
 *
 * Through {@see BillingAdmin::refund()}, as {@see RefundKind::UnusedPrepaidPeriod}: the same rails, the same audit
 * record, and on a routed sale the same correction of both links of the chain. A second refund path beside it would
 * be the one forgotten at the next change.
 *
 * ## Once
 *
 * A subscription already canceled to a moment inside its period is refused, before anything moves. The end is
 * written onto the row here rather than left to the provider's webhook, so a second call a second later finds it:
 * on a routed sale each partial refund opens its own reversal, and a repeated call would move the money twice.
 */
final readonly class ProratedCancellation
{
    public function __construct(
        private SubscriptionActions $actions,
        private ReadsSubscriptionPayments $payments,
        private BillingAdmin $admin,
    ) {}

    /**
     * What a cancellation to this moment comes to, without ending anything or moving any money.
     *
     * A customer is shown the end and the refund before they confirm, and a confirmation has to name both.
     *
     * @throws CancellationUnavailable
     * @throws EndInsidePeriodIsFinal when the subscription is already canceled to a moment inside its period
     * @throws InvalidArgumentException when the moment has passed
     */
    public function quote(Model $owner, CarbonInterface $endsAt, ?MerchantScope $merchant = null, ?string $type = null): CancellationSettlement
    {
        [$subscription, $payment] = $this->read($owner, $merchant, $type);

        return $this->settle($subscription, $payment, $endsAt);
    }

    /**
     * End the subscription at the moment, or at the period end when that comes first, and pay back what was paid
     * for the time after it.
     *
     * @param  ?string  $reason  what happened in this case, recorded beside the refund
     * @param  ?Model  $actor  who did it, when somebody did
     *
     * @throws CancellationUnavailable
     * @throws EndInsidePeriodIsFinal when the subscription is already canceled to a moment inside its period
     * @throws InvalidArgumentException when the moment has passed
     */
    public function cancel(
        Model $owner,
        CarbonInterface $endsAt,
        ?MerchantScope $merchant = null,
        ?string $type = null,
        ?string $reason = null,
        ?Model $actor = null,
    ): CancellationSettlement {
        [$subscription, $payment] = $this->read($owner, $merchant, $type);
        $settlement = $this->settle($subscription, $payment, $endsAt);

        if ($this->endsAtPeriodEnd($subscription, $payment, $settlement->endsAt)) {
            $this->actions->cancel($owner, null, $merchant, $type);

            return $settlement;
        }

        $this->actions->cancelAt($owner, $settlement->endsAt, $merchant, $type);
        $subscription->update(['ends_at' => $settlement->endsAt]);

        if (! $payment instanceof SubscriptionPeriodPayment || ! $settlement->refundable instanceof Money || ! $settlement->refundable->isPositive()) {
            return $settlement;
        }

        $result = $this->admin->refund(
            $owner,
            $payment->chargeReference,
            $settlement->refundable,
            $reason,
            'prorated-cancellation:'.$payment->chargeReference.':'.$settlement->endsAt->getTimestamp(),
            $actor,
            RefundKind::UnusedPrepaidPeriod,
        );

        return new CancellationSettlement(
            $settlement->endsAt,
            $settlement->paid,
            $settlement->refundable,
            refundRefused: ! $result->successful,
            chargeReference: $payment->chargeReference,
        );
    }

    /**
     * The live subscription and the payment behind the period it is in.
     *
     * @return array{Subscription, ?SubscriptionPeriodPayment}
     *
     * @throws CancellationUnavailable
     * @throws EndInsidePeriodIsFinal
     */
    private function read(Model $owner, ?MerchantScope $merchant, ?string $type): array
    {
        $subscription = Subscription::model()::query()
            ->forOwner($owner)
            ->ofType($type)
            ->forMerchant($merchant)
            ->latest('id')
            ->first();

        if (! $subscription instanceof Subscription || $subscription->isReplaceableByANewSubscription() || $subscription->terminated()) {
            throw CancellationUnavailable::noLiveSubscription(($merchant ?? MerchantScope::platform())->uid());
        }

        $early = $subscription->endInsideItsPeriod();

        if ($early instanceof Carbon) {
            throw EndInsidePeriodIsFinal::alreadyEnding($early);
        }

        try {
            return [$subscription, $this->payments->currentPeriodPayment($subscription)];
        } catch (SubscriptionWithdrawalUnavailable $inFlight) {
            // The reader's one refusal: a payment for the period is still on its way.
            throw CancellationUnavailable::paymentInFlight($inFlight);
        }
    }

    /**
     * The end that applies and the figures at it, computed and not acted on.
     *
     * @throws InvalidArgumentException when the moment has passed
     */
    private function settle(Subscription $subscription, ?SubscriptionPeriodPayment $payment, CarbonInterface $endsAt): CancellationSettlement
    {
        $end = CarbonImmutable::instance($endsAt);
        $periodEnd = $this->periodEnd($subscription, $payment);

        if ($periodEnd instanceof CarbonImmutable && ! $end->lessThan($periodEnd)) {
            // At or after the period end: the ordinary cancellation, and nothing of the period goes back.
            $end = $periodEnd;
        } else {
            $subscription->assertCanEndAt($end);
        }

        if (! $payment instanceof SubscriptionPeriodPayment) {
            return new CancellationSettlement($end);
        }

        $days = $payment->periodDays();

        if ($days < 1 || $end->equalTo($payment->periodEnd)) {
            return new CancellationSettlement($end, $payment->gross, Money::zero($payment->gross->currency));
        }

        $refundable = ProratedTermRefund::unusedPortion($payment->gross, $payment->elapsedDaysAt($end), $days);

        return new CancellationSettlement($end, $payment->gross, $refundable);
    }

    /** The end of the period in progress: the paid period when there is a payment, the row's own cycle otherwise. */
    private function periodEnd(Subscription $subscription, ?SubscriptionPeriodPayment $payment): ?CarbonImmutable
    {
        if ($payment instanceof SubscriptionPeriodPayment) {
            return $payment->periodEnd;
        }

        return $subscription->current_period_end instanceof Carbon ? CarbonImmutable::instance($subscription->current_period_end) : null;
    }

    private function endsAtPeriodEnd(Subscription $subscription, ?SubscriptionPeriodPayment $payment, CarbonImmutable $end): bool
    {
        return $this->periodEnd($subscription, $payment)?->equalTo($end) === true;
    }
}
