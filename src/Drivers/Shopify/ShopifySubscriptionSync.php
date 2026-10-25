<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Shopify;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\SubscriptionSync;
use Pushery\Billing\Events\SubscriptionStateChanged;
use Pushery\Billing\Models\Subscription;

/**
 * Reads the owner's active Shopify subscription back as the event a webhook would produce.
 *
 * An installation has at most one active subscription. A subscription the app never opened is not pulled, since
 * nothing says which tier it sells. Where the installation reports none active at all, the owner's latest Shopify
 * subscription is read back by its id, because Shopify lists only active subscriptions: one it canceled, as it does
 * when a merchant removes the app, would otherwise stay active here whatever the sync is run for. While another
 * subscription of the installation is active, nothing is pulled, as the webhook reports a replaced one as nothing.
 *
 * The event is dated when it is read, because it carries the state Shopify reports at that moment.
 */
final readonly class ShopifySubscriptionSync implements SubscriptionSync
{
    private const string ACTIVE = <<<'GRAPHQL'
        query BillingActiveSubscriptions {
          currentAppInstallation {
            activeSubscriptions { id status createdAt currentPeriodEnd trialDays }
          }
        }
        GRAPHQL;

    private const string LATEST = <<<'GRAPHQL'
        query BillingLatestSubscription($id: ID!) {
          node(id: $id) {
            ... on AppSubscription { id status createdAt currentPeriodEnd trialDays }
          }
        }
        GRAPHQL;

    public function __construct(
        private ShopifyAdmin $admin,
        private ShopifyShops $shops,
    ) {}

    public function pull(Model $billable): ?SubscriptionStateChanged
    {
        $shop = $this->shops->of($billable);
        $installation = $this->admin->query($shop, self::ACTIVE)['currentAppInstallation'] ?? null;
        $active = is_array($installation) ? ($installation['activeSubscriptions'] ?? null) : null;
        $active = is_array($active) ? $active : [];

        foreach ($active as $node) {
            $id = is_array($node) ? ($node['id'] ?? null) : null;
            $record = is_string($id) ? ShopifySubscriptionRecord::find($id) : null;

            if (is_array($node) && is_string($id) && $record instanceof ShopifySubscriptionRecord) {
                return ShopifySubscriptionState::event($shop, $id, $node, $record, CarbonImmutable::now());
            }
        }

        return $active === [] ? $this->latest($billable, $shop) : null;
    }

    /** The owner's latest Shopify subscription as the shop reports it now, or null where it has none that ran. */
    private function latest(Model $billable, string $shop): ?SubscriptionStateChanged
    {
        $id = Subscription::model()::query()
            ->forOwner($billable)
            ->where('provider', 'shopify')
            ->whereNotNull('provider_id')
            ->latest('id')
            ->value('provider_id');

        $record = is_string($id) && $id !== '' ? ShopifySubscriptionRecord::find($id) : null;

        if (! is_string($id) || ! $record instanceof ShopifySubscriptionRecord) {
            return null;
        }

        $node = $this->admin->query($shop, self::LATEST, ['id' => $id])['node'] ?? null;

        if (! is_array($node) || ($node['id'] ?? null) !== $id || in_array(ShopifySubscriptionState::status($node), ShopifySubscriptionState::NEVER_RAN, true)) {
            return null;
        }

        return ShopifySubscriptionState::event($shop, $id, $node, $record, CarbonImmutable::now());
    }
}
