<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use Pushery\Billing\Catalogs\TierPriceIndex;
use Pushery\Billing\Catalogs\TierPriceIndexFactory;
use Pushery\Billing\Enums\SubscriptionState;
use Pushery\Billing\Events\SubscriptionStateChanged;
use Pushery\Billing\ValueObjects\MerchantScope;

/**
 * Turns one raw Stripe subscription object into the neutral SubscriptionStateChanged event the plan-sync
 * effect acts on. This is shared ground: a webhook delivers the object, and the post-checkout reconcile
 * pulls the same object off the API — both must collapse the status and resolve the tier by the SAME
 * rules, or the redirect and the webhook would disagree about what a customer is on.
 *
 * The tier is read from whichever item carries a configured tier price (never data[0] blindly — a
 * subscription may also hold a metered component or an app-owned item, and Stripe does not promise their
 * order; a null tier on an access-granting subscription is how a paying owner gets downgraded).
 */
final readonly class StripeSubscriptionMapper
{
    public function __construct(private TierPriceIndexFactory $indexes) {}

    /**
     * @param  array<array-key, mixed>  $subscription
     *
     * `$merchantAccount` is the provider account the event fired on — passed straight onto the event so the
     * plan-sync effect can resolve the owner account-scoped. It is a second identity from `$merchant`: the
     * scope keys the local row, the account reference scopes the customer lookup. Null for a platform event.
     */
    public function toEvent(array $subscription, ?int $occurredAt, ?MerchantScope $merchant = null, ?string $merchantAccount = null): ?SubscriptionStateChanged
    {
        $customer = $this->string($subscription, 'customer');
        $id = $this->string($subscription, 'id');

        if ($customer === null || $id === null) {
            return null;
        }

        // The tier is resolved against the FIRING merchant's catalog, so a connected-account webhook reads
        // the creator's tiers and never the platform's. A null merchant reads the platform catalog, byte-for-
        // byte the single-seller behavior.
        $index = $this->indexes->for($merchant);
        $tierItem = $this->tierItem($subscription, $index);

        return new SubscriptionStateChanged(
            customerReference: $customer,
            state: $this->mapState($subscription),
            subscriptionReference: $id,
            tierKey: $this->tierKey($subscription, $index),
            occurredAt: $occurredAt,
            periodStart: $this->period($subscription, $tierItem, 'current_period_start'),
            periodEnd: $this->period($subscription, $tierItem, 'current_period_end'),
            trialEnd: $this->int($subscription, 'trial_end'),
            merchant: $merchant,
            merchantAccountReference: $merchantAccount,
            declarationReference: $this->declaration($subscription),
            startedAt: $this->int($subscription, 'start_date'),
            couponCode: $this->mintedCouponCode($subscription),
            subscriptionType: $this->subscriptionType($subscription),
            callerReference: $this->callerReference($subscription),
            endsAt: $this->endsAt($subscription),
        );
    }

    /**
     * When the subscription ends: Stripe's `cancel_at` while an end is scheduled, its `ended_at` once it ended.
     *
     * `cancel_at` is what a cancellation to a date sets, and it is the moment access stops. Reading the period
     * end instead would promise the owner the rest of a period that may have been refunded. A subscription
     * with neither answers null, and a grace period without a `cancel_at` is read from the period end where
     * the event is consumed.
     *
     * @param  array<array-key, mixed>  $subscription
     */
    private function endsAt(array $subscription): ?int
    {
        return $this->string($subscription, 'status') === 'canceled'
            ? $this->int($subscription, 'ended_at')
            : $this->int($subscription, 'cancel_at');
    }

    /**
     * WHICH contract of this owner at this merchant the subscription is, or null for the default one.
     *
     * Read off the same `metadata` the withdrawal key travels in, and for the same reason: it is the only
     * field that lands on the subscription object and stays there for every later event about it. Without it
     * a second contract with one merchant has no way home — the sync would lock the first contract's row and
     * write this one's state onto it, which is the defect this field exists to close.
     *
     * An empty value is no type rather than a type named "": a row keyed on the empty string would be a
     * third contract nobody asked for, and it would not be the default one either.
     *
     * @param  array<array-key, mixed>  $subscription
     */
    private function subscriptionType(array $subscription): ?string
    {
        $metadata = $subscription['metadata'] ?? null;
        $value = is_array($metadata) ? $this->string($metadata, 'subscription_type') : null;

        return $value === '' ? null : $value;
    }

    /**
     * The caller's own correlation key, stamped onto the subscription at checkout, or null.
     *
     * Read off the same `metadata` the other two travel in, for the same reason: it is the only field that
     * lands on the subscription object and stays there for every later event about it. That property is what
     * makes this usable for a SUBSCRIPTION at all — the subscription reference does not exist yet when the
     * session is opened, so a consumer opening a checkout has nothing else to correlate on.
     *
     * An empty value is no key rather than a key named "": a lookup on the empty string finds nothing and
     * would read as "this purchase named no row" when the caller did name one.
     *
     * @param  array<array-key, mixed>  $subscription
     */
    private function callerReference(array $subscription): ?string
    {
        $metadata = $subscription['metadata'] ?? null;
        $value = is_array($metadata) ? $this->string($metadata, 'caller_reference') : null;

        return $value === '' ? null : $value;
    }

    /**
     * The withdrawal-declaration key the checkout stamped onto the subscription, or null.
     *
     * Read off `metadata`, where `subscription_data.metadata` lands on the subscription object and stays for
     * every later event about it. Anything that is not a non-empty string is no key: an empty value coming back
     * must not become a reference that finds nothing and reads as "the buyer declared nothing".
     *
     * @param  array<array-key, mixed>  $subscription
     */
    private function declaration(array $subscription): ?string
    {
        $metadata = $subscription['metadata'] ?? null;
        $value = is_array($metadata) ? $this->string($metadata, 'withdrawal_declaration') : null;

        return $value === '' ? null : $value;
    }

    /**
     * The subscription's current cycle. Stripe moved `current_period_start`/`_end` off the subscription
     * and onto each ITEM, so the tier item is where the cycle we bill usage into lives; the subscription
     * root is read as a fallback for an older API version.
     *
     * @param  array<array-key, mixed>  $subscription
     * @param  ?array<array-key, mixed>  $tierItem
     */
    private function period(array $subscription, ?array $tierItem, string $key): ?int
    {
        $onItem = $tierItem === null ? null : ($tierItem[$key] ?? null);

        if (is_int($onItem)) {
            return $onItem;
        }

        return $this->int($subscription, $key);
    }

    /**
     * The subscription item whose price maps to a configured tier, or the only item when none does.
     *
     * @param  array<array-key, mixed>  $subscription
     * @return ?array<array-key, mixed>
     */
    private function tierItem(array $subscription, TierPriceIndex $index): ?array
    {
        $fallback = null;

        foreach ($this->items($subscription) as $item) {
            $price = $item['price'] ?? null;

            if (is_array($price) && $index->isTierPrice($this->string($price, 'id'))) {
                return $item;
            }

            $fallback ??= $item;
        }

        return $fallback;
    }

    /**
     * @param  array<array-key, mixed>  $subscription
     * @return list<array<array-key, mixed>>
     */
    private function items(array $subscription): array
    {
        $items = $subscription['items'] ?? null;
        $data = is_array($items) ? ($items['data'] ?? null) : null;

        if (! is_array($data)) {
            return [];
        }

        return array_values(array_filter($data, is_array(...)));
    }

    /**
     * Collapse Stripe's subscription status onto the neutral state. A subscription set to cancel, at the
     * period end or at a date inside the period, but still paid is the grace period, not a plain active one.
     *
     * @param  array<array-key, mixed>  $subscription
     */
    private function mapState(array $subscription): SubscriptionState
    {
        $status = $this->string($subscription, 'status');

        // A paused subscription is checked FIRST, and it is checked on `pause_collection` — not on the
        // status. Stripe leaves the status `active` when collection is paused, so a pause taken in the
        // hosted portal (which this package links) would otherwise read as a paying customer: they keep
        // the paid tier while Stripe raises no invoices. Free, forever. The `paused` STATUS is a
        // different thing (a trial that ended with no payment method) and lands here too — same truth:
        // nothing is being billed.
        if (is_array($subscription['pause_collection'] ?? null) || $status === 'paused') {
            return SubscriptionState::Paused;
        }

        $ending = ($subscription['cancel_at_period_end'] ?? false) === true || is_int($subscription['cancel_at'] ?? null);

        if ($ending && in_array($status, ['active', 'trialing'], true)) {
            return SubscriptionState::Grace;
        }

        return match ($status) {
            'active' => SubscriptionState::Active,
            'trialing' => SubscriptionState::Trialing,
            'past_due', 'unpaid' => SubscriptionState::PastDue,
            'incomplete' => SubscriptionState::Incomplete,
            'incomplete_expired' => SubscriptionState::IncompleteExpired,
            'canceled' => SubscriptionState::Ended,
            default => SubscriptionState::Churned,
        };
    }

    /**
     * The tier a subscription is on, read from whichever of its items carries a configured tier price.
     * Every item is scanned, never just the first: Stripe does not promise the order of `items.data`.
     *
     * @param  array<array-key, mixed>  $subscription
     */
    private function tierKey(array $subscription, TierPriceIndex $index): ?string
    {
        foreach ($this->items($subscription) as $item) {
            $price = $item['price'] ?? null;
            $tier = is_array($price) ? $index->tierForPrice($this->string($price, 'id')) : null;

            if ($tier !== null) {
                return $tier;
            }
        }

        return null;
    }

    /**
     * The LOCAL code of a MINTED merchant coupon the provider applied to this subscription, or null.
     *
     * Read off the coupon's own `metadata`, where {@see StripePlatformCouponProvisioner} writes
     * `billing_coupon_code` when it mints. So the mapping back is one the package laid down itself on the
     * way out -- no lookup, no query, and no rebuilding a local row from a provider id.
     *
     * THE NULL IS AS MEANINGFUL AS THE VALUE, and that is why this reads metadata rather than the coupon id.
     * A CATALOG coupon (`billing.coupons.<code>.stripe_coupon`) was created by a human at the provider, with
     * the provider's own max_redemptions, so the provider is the authority there and a consumer has nothing
     * to book. Such a coupon carries no `billing_coupon_code`, so it answers null -- correctly. Keying on the
     * id would have made both look alike and invited a consumer to book a redemption nobody is counting.
     *
     * Both shapes are read because the provider moved this field: `discount` is the older single object,
     * `discounts` the list that replaced it. Reading only one of them would answer null on half the API
     * versions, which is the failure mode this package has already paid for once on an invoice field.
     *
     * @param  array<array-key, mixed>  $subscription
     */
    private function mintedCouponCode(array $subscription): ?string
    {
        $candidates = [];

        $single = $subscription['discount'] ?? null;

        if (is_array($single)) {
            $candidates[] = $single;
        }

        $many = $subscription['discounts'] ?? null;

        if (is_array($many)) {
            foreach ($many as $discount) {
                if (is_array($discount)) {
                    $candidates[] = $discount;
                }
            }
        }

        foreach ($candidates as $discount) {
            $coupon = $discount['coupon'] ?? null;

            if (! is_array($coupon)) {
                continue;
            }

            $metadata = $coupon['metadata'] ?? null;

            if (! is_array($metadata)) {
                continue;
            }

            $code = $metadata['billing_coupon_code'] ?? null;

            // A non-empty string or nothing. An empty value coming back must not become a code that matches
            // no row and reads to a consumer as "a coupon was redeemed" when none was.
            if (is_string($code) && trim($code) !== '') {
                return $code;
            }
        }

        return null;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function string(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function int(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return is_int($value) ? $value : null;
    }
}
