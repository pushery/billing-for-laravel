<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Pushery\Billing\Contracts\MerchantTierRepository;
use Pushery\Billing\ValueObjects\MerchantScope;
use Pushery\Billing\ValueObjects\MerchantTierRow;

/**
 * Answers a merchant's tiers once per scope, then from memory.
 *
 * ## The read pattern this exists for
 *
 * The reverse price lookup — which tier does this provider price belong to — walks a merchant's tier keys
 * and asks for each key's current price and its legacy prices. Against a config array that is free. Against
 * rows it is not: the walk asks the repository roughly **2N+1 times for one answer**, and it runs on the
 * webhook path, per event, per merchant. A host that implements `tiersFor()` as a plain query — the obvious
 * way, and the way the contract reads — turns every incoming subscription event into a burst of identical
 * queries.
 *
 * That cost is invisible in tests: an in-memory fake answers instantly, so the suite is green at any N, and
 * the storm only appears under a real database with real traffic.
 *
 * ## Why the package memoizes rather than asking hosts to
 *
 * The repository is implemented by the HOST, so the package cannot make its queries cheap. What it can do is
 * stop asking the same question repeatedly. Wrapping here means a naive implementation is correct AND fast,
 * instead of correct and quietly expensive — and a host that already caches loses nothing but a hash lookup.
 *
 * ## Lifetime
 *
 * One request. The memo lives on the instance, and the instance is built per resolution of the catalog, so
 * a tier edited between two requests is picked up by the next one. Within a single webhook it is deliberately
 * frozen: a reverse lookup that saw one price list halfway through and another afterwards could resolve two
 * items of the same subscription to different tiers.
 */
final class MemoizedMerchantTierRepository implements MerchantTierRepository
{
    /** @var array<string, array<string, MerchantTierRow>> */
    private array $tiers = [];

    public function __construct(private readonly MerchantTierRepository $inner) {}

    /** @return array<string, MerchantTierRow> */
    public function tiersFor(MerchantScope $scope): array
    {
        return $this->tiers[$scope->uid()] ??= $this->inner->tiersFor($scope);
    }

    public function tierRow(MerchantScope $scope, string $key): ?MerchantTierRow
    {
        // Served from the same memo rather than passed through. Asking the inner repository for a single row
        // would send a second query for something the first answer already contains — and would let the two
        // disagree, which is the one thing a memo must not permit.
        return $this->tiersFor($scope)[$key] ?? null;
    }
}
