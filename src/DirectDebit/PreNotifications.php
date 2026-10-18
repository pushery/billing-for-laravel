<?php

declare(strict_types=1);

namespace Pushery\Billing\DirectDebit;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Pushery\Billing\Contracts\CollectsDirectDebits;
use Pushery\Billing\Contracts\PaymentRails;
use Pushery\Billing\Models\DirectDebitNotice;
use Pushery\Billing\Models\MandateConsent;
use Pushery\Billing\Models\Order;
use Pushery\Billing\Models\PaymentMandate;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\Notifications\DirectDebitAnnouncedNotification;
use Pushery\Billing\ValueObjects\Money;
use Throwable;

/**
 * The notices the local engine sends before it draws a SEPA direct debit, and what they cover.
 *
 * The SEPA core rulebook lets a direct debit be drawn only once the payer was told its amount and its day, the
 * agreed number of calendar days before. The period is the one the mandate's consent records where the account
 * holder gave the mandate on the application's form, and `billing.direct_debit.notice_days` otherwise.
 *
 * Each notice names the first day its debit may be drawn, `debit_on`, which is never sooner than the period from
 * the day it went out. A notice covers a debit from that day on:
 *
 * - The newest notice for a subscription covers its amount on each later due date as well, so a subscription whose
 *   amount stays the same is announced once. A different amount needs a new notice, and so does another mandate.
 * - A notice that names an order covers that order's charge alone: the rest of a period after a change, a late
 *   fee, or the retry of a cycle the bank returned.
 */
final readonly class PreNotifications
{
    /** The period the rulebook sets where nothing else is agreed, which an unusable configured value falls back to. */
    private const int RULEBOOK_NOTICE_DAYS = 14;

    public function __construct(private Repository $config) {}

    /** The configured number of days a debit is announced before it is drawn. */
    public function configuredDays(): int
    {
        $days = $this->config->get('billing.direct_debit.notice_days');

        return is_int($days) && $days >= 1 ? $days : self::RULEBOOK_NOTICE_DAYS;
    }

    /** The longest period any mandate here is announced by, which is how far ahead the engine has to look. */
    public function longestDays(): int
    {
        $agreed = MandateConsent::model()::query()->max('notice_days');

        return max($this->configuredDays(), is_numeric($agreed) ? (int) $agreed : 0);
    }

    /** Whether a charge against this mandate is drawn through these rails as a SEPA direct debit. */
    public function drawsDirectDebit(PaymentRails $rails, PaymentMandate $mandate): bool
    {
        return $rails instanceof CollectsDirectDebits && $rails->drawsDirectDebit($mandate->toReference());
    }

    /** The days a debit against this mandate is announced before it is drawn. */
    public function daysFor(PaymentMandate $mandate): int
    {
        $consent = MandateConsent::forMandate($mandate->provider, $mandate->mandate_reference);

        return $consent instanceof MandateConsent && $consent->notice_days >= 1 ? $consent->notice_days : $this->configuredDays();
    }

    /** The newest notice for this subscription's cycles against this mandate, or null when there is none. */
    public function forCycles(PaymentMandate $mandate, Subscription $subscription): ?DirectDebitNotice
    {
        return DirectDebitNotice::model()::query()
            ->where('provider', $mandate->provider)
            ->where('mandate_reference', $mandate->mandate_reference)
            ->where('subscription_id', $subscription->getKey())
            ->where('recurring', true)
            ->orderByDesc('id')
            ->first();
    }

    /** The newest notice for this order's charge against this mandate, or null when there is none. */
    public function forCharge(PaymentMandate $mandate, Order $order): ?DirectDebitNotice
    {
        return DirectDebitNotice::model()::query()
            ->where('provider', $mandate->provider)
            ->where('mandate_reference', $mandate->mandate_reference)
            ->where('order_id', $order->getKey())
            ->orderByDesc('id')
            ->first();
    }

    /** Whether a notice announced this amount. */
    public function names(?DirectDebitNotice $notice, Money $amount): bool
    {
        return $notice instanceof DirectDebitNotice
            && $notice->amount_minor === $amount->minorUnits
            && $notice->currency === $amount->currency;
    }

    /** Whether the day a notice allows its debit from has come. */
    public function allowsOn(DirectDebitNotice $notice, CarbonInterface $moment): bool
    {
        return $notice->debit_on->toDateString() <= Carbon::instance($moment)->utc()->toDateString();
    }

    /**
     * Announce a debit, record the notice and tell the payer.
     *
     * The debit may be drawn from the planned day, or from the day the period ends counted from today when that is
     * later. A notice for an order covers that charge alone; one for a subscription without an order covers its
     * amount on each later due date as well.
     *
     * The notice goes to the address the account holder gave with the mandate, where there is one, and to the owner
     * otherwise.
     *
     * A notice is recorded only together with the message that tells the payer, because a recorded notice is what
     * lets the debit be drawn. With nobody to tell, nothing is recorded and null comes back. A message the queue
     * refuses takes its notice back with it, and the refusal travels, so the next run announces again instead of
     * drawing a debit nobody was told about. The message is queued after the commit of a transaction it is sent in,
     * which is why this runs outside one: a refusal there would come after the notice was committed.
     */
    public function announce(CollectsDirectDebits $rails, PaymentMandate $mandate, Money $amount, CarbonInterface $planned, ?Model $owner, ?Subscription $subscription = null, ?Order $order = null): ?DirectDebitNotice
    {
        $consent = MandateConsent::forMandate($mandate->provider, $mandate->mandate_reference);
        $address = $consent instanceof MandateConsent && $consent->notify_email !== null && $consent->notify_email !== '' ? $consent->notify_email : null;

        if ($address === null && ! $owner instanceof Model) {
            return null;
        }

        $now = Carbon::now()->utc();
        $debitOn = $now->copy()->addDays($this->daysFor($mandate))->startOfDay()->max(Carbon::instance($planned)->utc()->startOfDay());

        $notice = DirectDebitNotice::model()::query()->create([
            'owner_type' => $mandate->owner_type,
            'owner_id' => $mandate->owner_id,
            'provider' => $mandate->provider,
            'mandate_reference' => $mandate->mandate_reference,
            'subscription_id' => $subscription?->getKey(),
            'order_id' => $order?->getKey(),
            'amount_minor' => $amount->minorUnits,
            'currency' => $amount->currency,
            'debit_on' => $debitOn->toDateString(),
            'recurring' => ! $order instanceof Order,
            'announced_at' => $now,
        ]);

        $payee = $this->config->get('billing.company.name');

        $notification = new DirectDebitAnnouncedNotification(
            $amount,
            $debitOn,
            is_string($payee) && trim($payee) !== '' ? trim($payee) : $rails->creditor()->name,
            $rails->creditor()->identifier,
            ! $order instanceof Order,
            $consent?->reference,
            $consent?->account_last_four,
        );

        try {
            if ($address !== null) {
                Notification::route('mail', $address)->notify($notification);
            } elseif ($owner instanceof Model) {
                Notification::send($owner, $notification);
            }
        } catch (Throwable $refused) {
            $notice->delete();

            throw $refused;
        }

        return $notice;
    }
}
