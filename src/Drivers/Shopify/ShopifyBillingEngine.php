<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Shopify;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Pushery\Billing\Contracts\BillingEngine;
use Pushery\Billing\Enums\SubscriptionState;
use Pushery\Billing\Events\SubscriptionStateChanged;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\Webhooks\Effects\SyncPlanFromSubscription;

/**
 * The cycle of the Shopify driver, which Shopify runs, except for one end Shopify does not report.
 *
 * A subscription the app cancels is canceled at Shopify at once, and the merchant keeps the plan to the end of
 * the period they paid for. Shopify sends nothing when that end arrives, so each tick ends the subscriptions whose
 * end has passed: through the same plan sync a webhook goes through, which takes the plan back and writes the row.
 */
final readonly class ShopifyBillingEngine implements BillingEngine
{
    public function __construct(
        private ShopifyShops $shops,
        private SyncPlanFromSubscription $plans,
    ) {}

    public function tick(?DateTimeInterface $now = null): void
    {
        $moment = CarbonImmutable::instance($now ?? CarbonImmutable::now());

        $lapsed = Subscription::model()::query()
            ->where('provider', 'shopify')
            ->whereNotNull('provider_id')
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', $moment->utc())
            ->whereNotIn('status', [SubscriptionState::Ended->value, SubscriptionState::IncompleteExpired->value])
            ->lazyById();

        foreach ($lapsed as $subscription) {
            $record = ShopifySubscriptionRecord::find((string) $subscription->provider_id);

            if (! $record instanceof ShopifySubscriptionRecord) {
                continue;
            }

            ($this->plans)(new SubscriptionStateChanged(
                customerReference: $this->shops->of($record->owner),
                state: SubscriptionState::Ended,
                subscriptionReference: $subscription->provider_id,
                tierKey: $subscription->tier_key,
                occurredAt: $moment->getTimestamp(),
                periodEnd: $subscription->ends_at?->getTimestamp(),
            ));
        }
    }
}
