<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use Pushery\Billing\Catalogs\TierPriceIndex;
use Pushery\Billing\Enums\SubscriptionState;
use Pushery\Billing\Events\SubscriptionStateChanged;

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
    public function __construct(private TierPriceIndex $tiers) {}

    /**
     * @param  array<array-key, mixed>  $subscription
     */
    public function toEvent(array $subscription, ?int $occurredAt): ?SubscriptionStateChanged
    {
        $customer = $this->string($subscription, 'customer');
        $id = $this->string($subscription, 'id');

        if ($customer === null || $id === null) {
            return null;
        }

        $tierItem = $this->tierItem($subscription);

        return new SubscriptionStateChanged(
            customerReference: $customer,
            state: $this->mapState($subscription),
            subscriptionReference: $id,
            tierKey: $this->tierKey($subscription),
            occurredAt: $occurredAt,
            periodStart: $this->period($subscription, $tierItem, 'current_period_start'),
            periodEnd: $this->period($subscription, $tierItem, 'current_period_end'),
            trialEnd: $this->int($subscription, 'trial_end'),
        );
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
    private function tierItem(array $subscription): ?array
    {
        $fallback = null;

        foreach ($this->items($subscription) as $item) {
            $price = $item['price'] ?? null;

            if (is_array($price) && $this->tiers->isTierPrice($this->string($price, 'id'))) {
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
     * Collapse Stripe's subscription status onto the neutral state. A subscription set to cancel at
     * period end but still paid is the grace period, not a plain active one.
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

        if (($subscription['cancel_at_period_end'] ?? false) === true && in_array($status, ['active', 'trialing'], true)) {
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
    private function tierKey(array $subscription): ?string
    {
        foreach ($this->items($subscription) as $item) {
            $price = $item['price'] ?? null;
            $tier = is_array($price) ? $this->tiers->tierForPrice($this->string($price, 'id')) : null;

            if ($tier !== null) {
                return $tier;
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
