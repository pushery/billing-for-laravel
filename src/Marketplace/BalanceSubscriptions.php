<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Pushery\Billing\Contracts\BalanceSubscriptionTerms;
use Pushery\Billing\Contracts\InternalBalance;
use Pushery\Billing\Contracts\MerchantCatalog;
use Pushery\Billing\Enums\BalanceRenewalRefusal;
use Pushery\Billing\Enums\CollectionTiming;
use Pushery\Billing\Enums\MerchantChargePurpose;
use Pushery\Billing\Enums\SubscriptionState;
use Pushery\Billing\Events\BalanceSubscriptionNotRenewed;
use Pushery\Billing\Events\BalanceSubscriptionRenewed;
use Pushery\Billing\Exceptions\BalanceSubscriptionRefused;
use Pushery\Billing\Exceptions\EndInsidePeriodIsFinal;
use Pushery\Billing\Exceptions\InsufficientInternalBalance;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\Models\SubscriptionItem;
use Pushery\Billing\ValueObjects\BalanceCycleTerms;
use Pushery\Billing\ValueObjects\MerchantScope;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\Plan;
use Throwable;

/**
 * A merchant's subscription paid from the internal balance a host keeps for its users, with no provider involved.
 *
 * A subscription sold through a provider is renewed by the provider, and each period comes back as a paid invoice.
 * One paid from a balance has nobody to renew it, so this class does: the hourly `billing:run` takes every due
 * period from the buyer's balance.
 *
 * ## Every period is a sale
 *
 * Each period is sold through `RoutedPayment::chargeFromBalance()`, so it has what a sale of the merchant has: the
 * gates, the commission, the charge row (with the purpose `subscription`), the merchant's share on their balance and
 * the buyer's document. A period is debited under a key of the subscription and the start of the period, so a run
 * that repeats a period takes nothing twice.
 *
 * ## The price the buyer agreed to
 *
 * The price comes from the merchant's catalog when the subscription starts, never from the caller, and is kept on the
 * subscription's item. A renewal takes that price, so a price the merchant changes later does not reach a running
 * subscription. The length of a period comes from the catalog at each renewal, and a tier the merchant no longer
 * sells ends the subscription with the last period it paid for.
 *
 * ## A balance that does not cover a period
 *
 * `billing.marketplace.internal_balance.subscriptions.shortfall` decides. Under `retry`, the shipped default, the
 * subscription goes into arrears as a merchant's subscription with a refused card does: it becomes `past_due`, its
 * arrears clock starts, `billing:dunning:remind` reminds the buyer inside the cure window and `billing:dunning:expire`
 * ends the subscription when the window has run out. Until then the period is asked for once a day, and the period
 * stays where it was: a retry that succeeds pays the period that was due, not a new one from the day of payment.
 * Under `end` the subscription ends with the last period it paid for. `BalanceSubscriptionNotRenewed` is announced on
 * the first refusal and on an end, not on every daily retry, which the reminder already covers. A renewal the host
 * declines, or that no `BalanceSubscriptionTerms` binding can price, is not retried: it ends with its paid period.
 */
final readonly class BalanceSubscriptions
{
    /** The prefix of the key a period is debited under. */
    private const string KEY_PREFIX = 'balance-subscription:';

    public function __construct(
        private RoutedPayment $payment,
        private MerchantCatalog $catalogs,
        private Repository $config,
        /** Null where the host binds no terms; then no subscription paid from a balance is renewed. */
        private ?BalanceSubscriptionTerms $terms = null,
        private ?Dispatcher $events = null,
    ) {}

    /**
     * Subscribe the buyer to one of the merchant's tiers and take the first period from the buyer's balance.
     *
     * The first period's sale and the subscription are written in one database transaction, so a balance kept in the
     * same database is never debited for a subscription that was not written. A buyer whose last subscription with
     * the merchant has ended starts again on the same row, as with a provider.
     *
     * @param  BalanceCycleTerms  $terms  what the first period is sold under
     * @param  string  $idempotencyKey  the key of this subscription attempt, stable across a retry of it
     *
     * @throws BalanceSubscriptionRefused where the merchant sells no such tier, or the buyer already holds a running
     *                                    subscription with the merchant
     * @throws InsufficientInternalBalance where the balance does not cover the first period, and then nothing is written
     */
    public function subscribe(
        Model $merchant,
        Model $buyerOwner,
        string $tierKey,
        BalanceCycleTerms $terms,
        string $idempotencyKey,
        ?string $type = null,
        ?string $declarationReference = null,
    ): Subscription {
        $scope = MerchantScope::forMerchant($merchant);
        $plan = $this->catalogs->planCatalog($scope)->planFor($tierKey) ?? throw BalanceSubscriptionRefused::tierNotSold($tierKey);

        return Subscription::model()::query()->getConnection()->transaction(function () use ($merchant, $buyerOwner, $tierKey, $terms, $idempotencyKey, $type, $declarationReference, $scope, $plan): Subscription {
            $existing = Subscription::model()::query()
                ->forOwner($buyerOwner)
                ->ofType($type)
                ->forMerchant($scope)
                ->lockForUpdate()
                ->latest('id')
                ->first();

            if ($existing instanceof Subscription && ! $existing->isReplaceableByANewSubscription()) {
                throw BalanceSubscriptionRefused::alreadySubscribed();
            }

            $start = CarbonImmutable::now()->utc();
            $end = $plan->interval->advance($start, $start);

            $this->payment->chargeFromBalance(
                $merchant, $buyerOwner, $plan->amount, $terms->fee, $terms->taxBps, $terms->buyerIsDomestic,
                self::KEY_PREFIX.'start:'.$idempotencyKey, $terms->archetype,
                destinationCountry: $terms->destinationCountry,
                placement: $terms->placement,
                purpose: MerchantChargePurpose::Subscription,
            );

            $subscription = $existing ?? $this->newRow($buyerOwner, $scope, $type);

            $subscription->forceFill([
                'provider' => InternalBalance::PROVIDER,
                'provider_id' => 'balance_'.bin2hex(random_bytes(12)),
                'status' => SubscriptionState::Active->value,
                'tier_key' => $tierKey,
                'scheduled_tier_key' => null,
                'scheduled_swap_at' => null,
                'ends_at' => null,
                'delinquent_since' => null,
                'dunning_level' => 0,
                'payment_reminded_on' => null,
                'terminated_at' => null,
                'paused_at' => null,
                'started_at' => $this->utc($start),
                'current_period_start' => $this->utc($start),
                'current_period_end' => $this->utc($end),
                'scheduled_processing_at' => $this->utc($end),
                'collection_timing' => CollectionTiming::InAdvance,
                'declaration_reference' => $declarationReference,
            ])->save();

            // The price the buyer agreed to, which every renewal takes.
            $subscription->items()->delete();
            $subscription->items()->create([
                'plan_key' => $tierKey,
                'quantity' => 1,
                'metered' => false,
                'amount_minor' => $plan->amount->minorUnits,
                'currency' => $plan->amount->currency,
            ]);

            return $subscription;
        });
    }

    /**
     * Take every due period of the subscriptions paid from a balance, and return how many were renewed.
     *
     * Each subscription is renewed under its own lock, and a failure in one is reported to the host's exception
     * handler and leaves the others to run. The failed one stays due and is asked again on the next run.
     */
    public function renewDue(?CarbonInterface $now = null): int
    {
        $moment = CarbonImmutable::instance($now ?? CarbonImmutable::now())->utc();
        $renewed = 0;

        $due = Subscription::model()::query()
            ->where('provider', InternalBalance::PROVIDER)
            ->dueForProcessing($moment)
            ->orderBy('id')
            ->get();

        foreach ($due as $subscription) {
            try {
                $renewed += $this->renew($subscription->id, $moment) ? 1 : 0;
            } catch (Throwable $failure) {
                $this->report($failure);
            }
        }

        return $renewed;
    }

    /**
     * End the subscription with the period it paid for. It keeps its access until then.
     *
     * A subscription in arrears has no paid period ahead of it and ends now. One already canceled to a moment inside
     * its period keeps that moment.
     */
    public function cancel(Subscription $subscription): void
    {
        if ($subscription->endInsideItsPeriod() instanceof Carbon) {
            return;
        }

        if ($subscription->pastDue()) {
            $this->end($subscription, CarbonImmutable::now()->utc());

            return;
        }

        $subscription->forceFill(['ends_at' => $subscription->current_period_end])->save();
    }

    /**
     * End the subscription at a moment inside the period it is in. The rest of the paid period is given up.
     *
     * @throws InvalidArgumentException where the moment has passed or lies after the period's end
     */
    public function cancelAt(Subscription $subscription, CarbonInterface $endsAt): void
    {
        $subscription->assertCanEndAt($endsAt);

        $subscription->forceFill([
            'ends_at' => $this->utc($endsAt),
            'scheduled_processing_at' => $this->utc($endsAt),
        ])->save();
    }

    /**
     * Take back a cancellation that has not landed yet, so the subscription renews at the end of its period again.
     *
     * @throws EndInsidePeriodIsFinal where it was canceled to a moment inside its period
     */
    public function resume(Subscription $subscription): void
    {
        if (! $subscription->onGracePeriod()) {
            return;
        }

        $early = $subscription->endInsideItsPeriod();

        if ($early instanceof Carbon) {
            throw EndInsidePeriodIsFinal::forResume($early);
        }

        $subscription->forceFill([
            'ends_at' => null,
            'scheduled_processing_at' => $subscription->current_period_end,
        ])->save();
    }

    /** End the subscription now. Nothing is taken and nothing is given back: the period was paid in advance. */
    public function cancelNow(Subscription $subscription): void
    {
        $this->end($subscription, CarbonImmutable::now()->utc());
    }

    /** Renew one subscription under its row lock, or leave it to the run that already did. */
    private function renew(int $id, CarbonImmutable $moment): bool
    {
        return Subscription::model()::query()->getConnection()->transaction(function () use ($id, $moment): bool {
            $subscription = Subscription::model()::query()
                ->whereKey($id)
                ->where('provider', InternalBalance::PROVIDER)
                ->dueForProcessing($moment)
                ->lockForUpdate()
                ->first();

            if (! $subscription instanceof Subscription) {
                return false;
            }

            // Canceled to the end of its period, or to a moment inside it: it ends there, and nothing more is taken.
            if ($subscription->ends_at instanceof Carbon && $subscription->ends_at->lessThanOrEqualTo($moment)) {
                $this->end($subscription, CarbonImmutable::instance($subscription->ends_at));

                return false;
            }

            $merchant = $subscription->merchant()->first();
            $buyer = $subscription->owner()->first();
            $item = $subscription->items()->first();
            $amount = $item instanceof SubscriptionItem ? Money::of((int) $item->amount_minor, $item->currency) : null;
            $plan = $merchant instanceof Model && $subscription->tier_key !== null
                ? $this->catalogs->planCatalog(MerchantScope::forMerchant($merchant))->planFor($subscription->tier_key)
                : null;

            if (! $merchant instanceof Model || ! $buyer instanceof Model || ! $amount instanceof Money || ! $plan instanceof Plan) {
                $this->notRenewed($subscription, $amount, BalanceRenewalRefusal::TierWithdrawn, $moment);

                return false;
            }

            $terms = $this->terms?->termsFor($subscription, $merchant, $buyer);

            if (! $terms instanceof BalanceCycleTerms) {
                $this->notRenewed($subscription, $amount, BalanceRenewalRefusal::Declined, $moment);

                return false;
            }

            // The period that is due, from the end of the last one paid. A retry asks for the same period under the same
            // key, so a debit that went through on an attempt that failed later is found instead of taken again.
            $cycle = $subscription->nextCycle($plan->interval);
            $from = CarbonImmutable::instance($subscription->current_period_end ?? $moment);

            try {
                $result = $this->payment->chargeFromBalance(
                    $merchant, $buyer, $amount, $terms->fee, $terms->taxBps, $terms->buyerIsDomestic,
                    self::KEY_PREFIX.$subscription->id.':'.$from->toDateString(), $terms->archetype,
                    destinationCountry: $terms->destinationCountry,
                    placement: $terms->placement,
                    purpose: MerchantChargePurpose::Subscription,
                );
            } catch (InsufficientInternalBalance) {
                $this->notRenewed($subscription, $amount, BalanceRenewalRefusal::Shortfall, $moment);

                return false;
            }

            $subscription->forceFill([
                ...$cycle,
                'status' => SubscriptionState::Active->value,
                'delinquent_since' => null,
                'dunning_level' => 0,
                'payment_reminded_on' => null,
            ])->save();

            $this->announce(new BalanceSubscriptionRenewed($subscription, $amount, $result->reference));

            return true;
        });
    }

    /**
     * A period that was not taken: in arrears and asked for again tomorrow, or the end of the subscription.
     *
     * Only a shortfall is retried, and only inside the cure window, whose end `billing:dunning:expire` decides. A
     * renewal the host declined, or that has no tier to sell, would be refused again tomorrow for the same reason.
     */
    private function notRenewed(Subscription $subscription, ?Money $amount, BalanceRenewalRefusal $reason, CarbonImmutable $moment): void
    {
        if ($reason === BalanceRenewalRefusal::Shortfall && $this->retries()) {
            $first = ! $subscription->delinquent_since instanceof Carbon;
            $retryAt = $moment->addDay();

            $subscription->forceFill([
                'status' => SubscriptionState::PastDue->value,
                'delinquent_since' => $subscription->delinquent_since ?? $this->utc($moment),
                'scheduled_processing_at' => $this->utc($retryAt),
            ])->save();

            if ($first) {
                $this->announce(new BalanceSubscriptionNotRenewed($subscription, $amount, $reason, $retryAt));
            }

            return;
        }

        $this->end($subscription, CarbonImmutable::instance($subscription->current_period_end ?? $moment));

        $this->announce(new BalanceSubscriptionNotRenewed($subscription, $amount, $reason, null));
    }

    /** End the row at the moment given, with nothing left on the schedule, so no run takes anything from it again. */
    private function end(Subscription $subscription, CarbonImmutable $at): void
    {
        $subscription->forceFill([
            'status' => SubscriptionState::Ended->value,
            'ends_at' => $this->utc($at),
            'scheduled_processing_at' => null,
            'scheduled_tier_key' => null,
            'scheduled_swap_at' => null,
        ])->save();
    }

    /** A new row for the buyer's subscription with the merchant. */
    private function newRow(Model $buyerOwner, MerchantScope $scope, ?string $type): Subscription
    {
        $class = Subscription::model();
        $subscription = new $class;

        $subscription->forceFill([
            'owner_type' => $buyerOwner->getMorphClass(),
            'owner_id' => $buyerOwner->getKey(),
            'type' => $type ?? Subscription::TYPE_DEFAULT,
            'merchant_uid' => $scope->uid(),
            'merchant_type' => $scope->type,
            'merchant_id' => $scope->id,
        ]);

        return $subscription;
    }

    /**
     * A moment as the subscription's columns read it back: a UTC `Carbon`.
     *
     * The columns are cast by `UtcDateTime`, and Eloquent hands back the object assigned to a class-cast attribute
     * until the row is loaded again. Assigned as a `CarbonImmutable`, a moment came back as one, which the row's own
     * `?Carbon` helpers, `endInsideItsPeriod()` among them, refuse with a type error.
     */
    private function utc(CarbonInterface $at): Carbon
    {
        return Carbon::instance($at)->utc();
    }

    private function retries(): bool
    {
        return $this->config->get('billing.marketplace.internal_balance.subscriptions.shortfall') !== 'end';
    }

    private function announce(object $event): void
    {
        $this->events?->dispatch($event);
    }

    private function report(Throwable $failure): void
    {
        $container = Container::getInstance();

        if ($container->bound(ExceptionHandler::class)) {
            $container->make(ExceptionHandler::class)->report($failure);
        }
    }
}
