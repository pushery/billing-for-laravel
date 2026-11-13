<?php

declare(strict_types=1);

namespace Pushery\Billing\PriceChanges;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Pushery\Billing\Contracts\PriceChangeNotifier;
use Pushery\Billing\Enums\BillingInterval;
use Pushery\Billing\Enums\SubscriptionState;
use Pushery\Billing\Models\PriceChangeAnnouncement;
use Pushery\Billing\Models\Subscription;
use Throwable;

/**
 * Tells every running subscription of a declared price change, once, and records from which day the change holds for it.
 *
 * A change is announced from its `announce_on`, to every subscription of a tier it names that is billed by the package's
 * own engine and still runs. The day an announcement names is the change's own, or later where the announcement comes
 * late, so the subscription still gets the full notice from the moment it was told. An annual subscription keeps its
 * price to the end of its year, so its day is the first renewal on or after that. Nothing is announced to a subscription
 * that began on or after the change's day, which ordered at the new price, nor to one that ends before the day it would
 * name, which ends at the old one.
 */
final readonly class PriceChangeAnnouncer
{
    /** The states of a subscription that has cycles still to be billed. */
    private const array RUNNING = [
        SubscriptionState::Trialing,
        SubscriptionState::Active,
        SubscriptionState::PastDue,
        SubscriptionState::Paused,
    ];

    public function __construct(private DeclaredPriceChanges $declared, private PriceChangeNotifier $notifier) {}

    /**
     * Announce every change that is due by now to the subscriptions of the provider it has not reached yet. A
     * subscription whose announcement fails is reported and stops none after it; the next run tries it again.
     *
     * @return array{announced: int, failed: int}
     */
    public function announce(DateTimeInterface $now, string $provider): array
    {
        $moment = CarbonImmutable::instance($now);
        $announced = 0;
        $failed = 0;

        foreach ($this->declared->all() as $change) {
            if ($change->announceOn->greaterThan($moment)) {
                continue;
            }

            Subscription::model()::query()
                ->where('provider', $provider)
                ->whereIn('tier_key', $change->tiers())
                ->whereIn('status', array_map(static fn (SubscriptionState $state): string => $state->value, self::RUNNING))
                ->lazyById()
                ->each(function (Subscription $subscription) use ($change, $moment, &$announced, &$failed): void {
                    try {
                        $told = $this->tell($change, $subscription, $moment);
                    } catch (Throwable $exception) {
                        Log::warning('billing: a price change could not be announced to a subscription', [
                            'price_change' => $change->key,
                            'subscription' => $subscription->getKey(),
                            'error' => $exception->getMessage(),
                        ]);
                        $told = false;
                    }

                    if ($told === true) {
                        $announced++;
                    } elseif ($told === false) {
                        $failed++;
                    }
                });
        }

        return ['announced' => $announced, 'failed' => $failed];
    }

    /**
     * Tell the subscription's owner of the change, once. True where the owner was told, false where they could not be,
     * null where there was nothing to tell.
     */
    private function tell(PriceChange $change, Subscription $subscription, CarbonImmutable $now): ?bool
    {
        $told = PriceChangeAnnouncement::model()::query()
            ->where('billing_subscription_id', $subscription->getKey())
            ->where('price_change', $change->key)
            ->exists();

        $began = $subscription->started_at;

        if ($told || ($began instanceof DateTimeInterface && CarbonImmutable::instance($began)->utc()->startOfDay()->greaterThanOrEqualTo($change->effectiveOn))) {
            return null;
        }

        $day = $this->dayFor($change, $subscription, $now);
        $endsAt = $subscription->ends_at;

        if ($endsAt instanceof DateTimeInterface && CarbonImmutable::instance($endsAt)->lessThan($day)) {
            return null;
        }

        $owner = $subscription->owner()->first();

        if (! $owner instanceof Model) {
            return false;
        }

        $this->notifier->priceChangeAnnounced($owner, $change, $day);

        PriceChangeAnnouncement::model()::query()->create([
            'billing_subscription_id' => $subscription->getKey(),
            'price_change' => $change->key,
            'announced_at' => $now,
            'effective_on' => $day,
        ]);

        return true;
    }

    /**
     * The day the change holds for the subscription from: the change's own or the first its notice allows from now,
     * whichever is later, and for an annual subscription the first renewal on or after that.
     */
    private function dayFor(PriceChange $change, Subscription $subscription, CarbonImmutable $now): CarbonImmutable
    {
        $day = $change->effectiveOnAfter($now);
        $renewal = $subscription->current_period_end;

        if ($subscription->billing_interval !== BillingInterval::Year || ! $renewal instanceof DateTimeInterface) {
            return $day;
        }

        $next = CarbonImmutable::instance($renewal)->utc()->startOfDay();

        while ($next->lessThan($day)) {
            $next = $next->addYearNoOverflow();
        }

        return $next;
    }
}
