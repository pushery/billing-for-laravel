<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Pushery\Billing\Contracts\RetentionHold;

/**
 * The shipped default: nothing is ever held.
 *
 * This is what keeps the seam from being a behavior change. An installation that binds no hold of its own
 * prunes exactly as it did before the seam existed — the gate asks, the answer is "none", every candidate
 * goes. A host binds its own only when it actually keeps legal holds.
 *
 * It answers with an empty list rather than by being absent, because absent would mean the gate has to
 * decide what a missing seam means — and the fail-closed direction would then refuse to prune anything on a
 * default installation. A default that does nothing is not the same as no default.
 */
final class NoRetentionHolds implements RetentionHold
{
    /**
     * @param  list<int|string>  $recordIds
     * @return list<int|string>
     */
    public function heldAmong(string $recordType, array $recordIds): array
    {
        return [];
    }
}
