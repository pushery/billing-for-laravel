<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\ValueObjects\MerchantScope;
use Pushery\Billing\ValueObjects\MerchantTierRow;

/**
 * How the package reads a merchant's own tiers — implemented by the HOST, never the package.
 *
 * A creator's tiers are the host's data, in a schema the host owns (the marketplace's `creator_tiers`
 * table), exactly as a billable's tier column is a host-owned column the package only reads. So the package
 * ships no migration for it; it ships this contract and reads through it. The host answers a merchant's tiers
 * as MerchantTierRow value objects, and the database-backed catalogs turn those into the same identities,
 * prices and plans the config catalogs produce.
 *
 * The anti-price-injection guarantee rests here: a tier resolves to a price ONLY from that merchant's own
 * row, so a key defined for merchant A can never resolve to merchant B's price, and a price the client
 * submitted resolves to nothing at all.
 *
 * ## Implement it plainly — the package handles the repetition
 *
 * The reverse price lookup on the webhook path walks a merchant's tier keys and asks about each one, so a
 * naive reading of these two methods would be called roughly 2N+1 times to answer a single question, per
 * event, per merchant. That is not your problem to solve: the package wraps whatever it is given in a
 * per-request memo, so one plain query per merchant is exactly right and a cache of your own buys nothing.
 *
 * What DOES matter is that both methods answer consistently for one scope within a request — the memo will
 * treat the first answer as the truth for the rest of it, which is deliberate: a reverse lookup that saw
 * one price list halfway through and another afterwards could resolve two items of one subscription to
 * different tiers.
 */
interface MerchantTierRepository
{
    /**
     * A merchant's tiers, keyed by tier key, in the merchant's own upgrade-ranking order.
     *
     * @return array<string, MerchantTierRow>
     */
    public function tiersFor(MerchantScope $scope): array;

    /** One of a merchant's tiers by key, or null when the merchant has not defined it (not purchasable). */
    public function tierRow(MerchantScope $scope, string $key): ?MerchantTierRow;
}
