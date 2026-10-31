<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Shopify;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Pushery\Billing\Contracts\CustomerDirectory;
use Pushery\Billing\Contracts\DerivesDeliveryKey;
use Pushery\Billing\Contracts\WebhookEventMapper;
use Pushery\Billing\Enums\SubscriptionState;
use Pushery\Billing\Events\AddonPurchased;
use Pushery\Billing\Events\SubscriptionStateChanged;
use Pushery\Billing\Models\ShopifyPurchase;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\ValueObjects\Money;
use Throwable;

/**
 * Translates Shopify's billing webhooks into the package's neutral events.
 *
 * `app_subscriptions/update` becomes a {@see SubscriptionStateChanged}, and `app_purchases_one_time/update` an
 * {@see AddonPurchased} once the purchase is active. The payload says which subscription or purchase moved; its
 * state is read back from the shop, which is what the package acts on. For a subscription that also gives the end
 * of the period, which the webhook does not carry, and whether another subscription replaced it. The topic is
 * read from the body rather than from the `X-Shopify-Topic` header, so a delivery replayed from its stored body
 * maps the same way. `app/uninstalled` is the one exception, below.
 *
 * Shopify keeps one subscription per installation and cancels the current one when a new one is approved. A
 * subscription that ends while another one of the installation is active was replaced, and it is reported as
 * nothing at all: reporting it as ended would take the merchant's plan away the moment they changed it.
 *
 * A subscription that never ran is reported as nothing either: one waiting for the merchant's approval, or one they
 * declined or let expire. It gave the merchant nothing, and a plan change through the checkout opens one beside the
 * subscription they keep, so reporting it would put its state in place of the one they pay for.
 *
 * A subscription or a purchase the app never opened is ignored, since nothing says whose it is or what it sells.
 *
 * `app/uninstalled` ends every subscription of the shop at once. Shopify cancels them as the merchant removes the app
 * and sends no `app_subscriptions/update` for it, and the shop no longer answers the app, so nothing is read back.
 * Its body is the shop, the same body `shop/update` sends, so that delivery is told apart by its `X-Shopify-Topic`
 * header. The shop it ends comes from the signed body, never from a header. A delivery rebuilt from its stored body,
 * as `billing:webhooks:replay` rebuilds one, carries no header and ends nothing; Shopify's own retries carry it.
 */
final readonly class ShopifyWebhookEventMapper implements DerivesDeliveryKey, WebhookEventMapper
{
    private const string SUBSCRIPTION = <<<'GRAPHQL'
        query BillingSubscription($id: ID!) {
          node(id: $id) {
            ... on AppSubscription { id status createdAt currentPeriodEnd trialDays }
          }
          currentAppInstallation { activeSubscriptions { id } }
        }
        GRAPHQL;

    private const string PURCHASE = <<<'GRAPHQL'
        query BillingPurchase($id: ID!) {
          node(id: $id) {
            ... on AppPurchaseOneTime { id status }
          }
        }
        GRAPHQL;

    /** The body's object for each topic the driver maps. */
    private const array RESOURCES = ['app_subscription', 'app_purchase_one_time'];

    /** The topic of the one delivery Shopify sends when a merchant removes the app. */
    private const string UNINSTALLED = 'app/uninstalled';

    public function __construct(
        private ShopifyAdmin $admin,
        private ShopifyShops $shops,
        private CustomerDirectory $customers,
    ) {}

    public function map(Request $request): iterable
    {
        if ($this->isUninstall($request)) {
            return $this->uninstalled($request);
        }

        $subscription = $this->id($this->resource($request, 'app_subscription'));

        if ($subscription !== null) {
            $event = $this->subscriptionChanged($subscription);

            return $event instanceof SubscriptionStateChanged ? [$event] : [];
        }

        $resource = $this->resource($request, 'app_purchase_one_time');
        $purchase = $this->id($resource);
        $event = $purchase === null ? null : $this->purchased($purchase, $this->momentOf($resource['updated_at'] ?? null));

        return $event instanceof AddonPurchased ? [$event] : [];
    }

    /**
     * The subscription or purchase, its status and the moment Shopify changed it, which together tell a redelivery
     * from the next change of the same object. Read from the body, so a replayed delivery keeps its key.
     */
    public function deliveryKey(Request $request): ?string
    {
        // The shop and the moment it last changed. The shop's id alone, the receiver's own key for this body, would
        // file the uninstall after a reinstall as a repeat of the first one.
        if ($this->isUninstall($request)) {
            $shop = $this->uninstalledShop($request);
            $updated = $this->payload($request)['updated_at'] ?? null;

            return $shop === null ? null : 'shopify:uninstalled:'.$shop.':'.(is_string($updated) ? $updated : '');
        }

        foreach (self::RESOURCES as $key) {
            $resource = $this->resource($request, $key);
            $id = $this->id($resource);

            if ($resource !== null && $id !== null) {
                $status = $resource['status'] ?? null;
                $updated = $resource['updated_at'] ?? null;

                return 'shopify:'.$id.':'.(is_string($status) ? strtoupper($status) : '').':'.(is_string($updated) ? $updated : '');
            }
        }

        return null;
    }

    private function subscriptionChanged(string $id): ?SubscriptionStateChanged
    {
        $record = ShopifySubscriptionRecord::find($id);

        if (! $record instanceof ShopifySubscriptionRecord) {
            return null;
        }

        $shop = $this->shops->of($record->owner);
        $read = $this->admin->query($shop, self::SUBSCRIPTION, ['id' => $id]);
        $node = $read['node'] ?? null;

        if (! is_array($node) || ($node['id'] ?? null) !== $id) {
            return null;
        }

        $status = ShopifySubscriptionState::status($node);

        if (in_array($status, ShopifySubscriptionState::NEVER_RAN, true) || ($status === 'CANCELLED' && $this->replaced($read, $id))) {
            return null;
        }

        return ShopifySubscriptionState::event($shop, $id, $node, $record, CarbonImmutable::now());
    }

    /**
     * The purchase as the add-on purchase every driver reports, once Shopify reports it active, dated by the moment the
     * delivery says it last changed.
     */
    private function purchased(string $id, ?int $occurredAt): ?AddonPurchased
    {
        $purchase = ShopifyPurchase::model()::query()->where('purchase_id', $id)->first();
        $owner = $purchase instanceof ShopifyPurchase ? ShopifySubscriptionRecord::ownerOf($purchase->owner_type, $purchase->owner_id) : null;

        if (! $purchase instanceof ShopifyPurchase || ! $owner instanceof Model) {
            return null;
        }

        $shop = $this->shops->of($owner);
        $node = $this->admin->query($shop, self::PURCHASE, ['id' => $id])['node'] ?? null;

        if (! is_array($node) || ($node['id'] ?? null) !== $id || ShopifySubscriptionState::status($node) !== 'ACTIVE') {
            return null;
        }

        return new AddonPurchased(
            customerReference: $shop,
            addonKey: $purchase->addon_key,
            amount: Money::of($purchase->amount_minor, $purchase->currency),
            reference: $id,
            paymentReference: $id,
            declarationReference: $purchase->declaration_reference,
            provider: 'shopify',
            callerReference: $purchase->caller_reference,
            occurredAt: $occurredAt,
        );
    }

    /** A moment Shopify states as an ISO 8601 string, in Unix seconds, or null where it states none it can read. */
    private function momentOf(mixed $value): ?int
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->getTimestamp();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Every subscription of the shop the app was removed from that has not ended yet, ended now.
     *
     * @return list<SubscriptionStateChanged>
     */
    private function uninstalled(Request $request): array
    {
        $shop = $this->uninstalledShop($request);
        $owner = $shop === null ? null : $this->customers->ownerForReference($shop);

        if ($shop === null || ! $owner instanceof Model) {
            return [];
        }

        $now = CarbonImmutable::now()->getTimestamp();
        $ended = [];

        $running = Subscription::model()::query()
            ->forOwner($owner)
            ->where('provider', 'shopify')
            ->whereNotIn('status', [SubscriptionState::Ended->value, SubscriptionState::Churned->value, SubscriptionState::IncompleteExpired->value])
            ->orderBy('id')
            ->get();

        foreach ($running as $subscription) {
            $ended[] = new SubscriptionStateChanged(
                customerReference: $shop,
                state: SubscriptionState::Ended,
                subscriptionReference: $subscription->provider_id,
                tierKey: $subscription->tier_key,
                occurredAt: $now,
                declarationReference: $subscription->declaration_reference,
                subscriptionType: $subscription->type,
                endsAt: $now,
            );
        }

        return $ended;
    }

    private function isUninstall(Request $request): bool
    {
        $topic = $request->header('X-Shopify-Topic');

        return is_string($topic) && strtolower(trim($topic)) === self::UNINSTALLED;
    }

    /** The shop an uninstall names in its body, by its permanent domain, or null where it names none. */
    private function uninstalledShop(Request $request): ?string
    {
        $domain = $this->payload($request)['myshopify_domain'] ?? null;
        $domain = is_string($domain) ? strtolower(trim($domain)) : '';

        return ShopifyAdmin::isShop($domain) ? $domain : null;
    }

    /**
     * Whether another subscription of the installation is active, which means this one was replaced.
     *
     * @param  array<array-key, mixed>  $read
     */
    private function replaced(array $read, string $id): bool
    {
        $installation = $read['currentAppInstallation'] ?? null;
        $active = is_array($installation) ? ($installation['activeSubscriptions'] ?? null) : null;

        return array_any(
            is_array($active) ? $active : [],
            static fn (mixed $subscription): bool => is_array($subscription)
                && is_string($subscription['id'] ?? null)
                && $subscription['id'] !== $id,
        );
    }

    /**
     * The object of a topic in the body, or null where the body carries another one.
     *
     * @return array<array-key, mixed>|null
     */
    private function resource(Request $request, string $key): ?array
    {
        $resource = $this->payload($request)[$key] ?? null;

        return is_array($resource) ? $resource : null;
    }

    /** @return array<array-key, mixed> */
    private function payload(Request $request): array
    {
        $payload = json_decode($request->getContent(), true);

        return is_array($payload) ? $payload : [];
    }

    /** @param array<array-key, mixed>|null $resource */
    private function id(?array $resource): ?string
    {
        $id = $resource === null ? null : ($resource['admin_graphql_api_id'] ?? null);

        return is_string($id) && $id !== '' ? $id : null;
    }
}
