<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use Pushery\Billing\Catalogs\TierPriceIndex;
use Stripe\Price;
use Stripe\StripeObject;
use Stripe\Subscription;
use Stripe\SubscriptionItem;

/**
 * Finds the item a subscription's TIER lives on.
 *
 * A subscription may legitimately carry more than one item — a metered component billed on top of the
 * base fee, or an item the host app added itself — and Stripe does not promise the order of
 * `items.data`. Addressing `items[0]` positionally is therefore how a plan swap reprices the metered
 * component, a seat sync writes a quantity onto a price that forbids one, and a tier lookup resolves
 * to nothing. Every subscription mutation goes through here instead, so an item this package did not
 * put there is left strictly alone.
 *
 * The base item is the one whose price the tier catalog knows. When no price matches — a grandfathered
 * tier the catalog no longer lists — the single non-metered item IS the base. When that is still
 * ambiguous, the answer is null and the caller fails loudly rather than mutating a guess.
 */
final readonly class StripeSubscriptionItems
{
    public function __construct(private TierPriceIndex $tiers) {}

    /** The item carrying the subscription's tier price, or null when it cannot be identified safely. */
    public function base(Subscription $subscription): ?SubscriptionItem
    {
        $items = $subscription->items->data;

        $tierItems = array_values(array_filter(
            $items,
            fn (SubscriptionItem $item): bool => $this->tiers->isTierPrice($this->priceId($item)),
        ));

        if (count($tierItems) === 1) {
            return $tierItems[0];
        }

        // Two items priced at two different tiers is a subscription this package cannot reason about;
        // so is an empty one. Refuse rather than pick.
        if ($tierItems !== []) {
            return null;
        }

        $candidates = array_values(array_filter(
            $items,
            fn (SubscriptionItem $item): bool => ! $this->isMetered($item),
        ));

        return count($candidates) === 1 ? $candidates[0] : null;
    }

    /**
     * Whether the item is billed by usage. Stripe rejects a quantity on such an item, and its price is
     * never a tier price, so it must survive every mutation untouched.
     */
    public function isMetered(SubscriptionItem $item): bool
    {
        $price = $this->price($item);

        if (! $price instanceof Price) {
            return false;
        }

        $recurring = $price->offsetGet('recurring');

        if (! $recurring instanceof StripeObject) {
            return false;
        }

        // `usage_type` is the classic metered flag; `meter` is the one a meter-backed price carries.
        if ($recurring->offsetGet('usage_type') === 'metered') {
            return true;
        }

        return is_string($recurring->offsetGet('meter'));
    }

    /** The item's provider price id, or null when the item carries no price. */
    public function priceId(SubscriptionItem $item): ?string
    {
        $id = $this->price($item)?->offsetGet('id');

        return is_string($id) ? $id : null;
    }

    /**
     * The item's price, read through ArrayAccess rather than the magic property: an item that carries no
     * price at all is a shape we must tolerate, and Stripe's `__get` emits a notice for an absent one.
     */
    private function price(SubscriptionItem $item): ?Price
    {
        $price = $item->offsetGet('price');

        return $price instanceof Price ? $price : null;
    }
}
