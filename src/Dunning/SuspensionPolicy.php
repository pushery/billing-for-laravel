<?php

declare(strict_types=1);

namespace Pushery\Billing\Dunning;

use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\ValueObjects\DunningLevel;

/**
 * The pure decision at the heart of the suspension ladder: whether a surface is withdrawn at a given
 * dunning level. Each surface is configured (config `billing.suspension`) with the dunning-level
 * position at which it locks; it is locked once the owner has reached that level or higher, so
 * surfaces can be pulled at different stages of delinquency. It reads no clock and touches no owner —
 * the stateful ladder feeds it the current level (from ConfigDunningLadder) — so a not-yet-delinquent
 * owner (null level) or an unconfigured surface simply never locks.
 */
final readonly class SuspensionPolicy
{
    public function __construct(private Repository $config) {}

    public function isLockedOut(string $surface, ?DunningLevel $level): bool
    {
        if (! $level instanceof DunningLevel) {
            return false;
        }

        $threshold = $this->threshold($surface);

        return $threshold !== null && $level->position >= $threshold;
    }

    private function threshold(string $surface): ?int
    {
        $map = $this->config->get('billing.suspension');
        $threshold = is_array($map) ? ($map[$surface] ?? null) : null;

        return is_int($threshold) && $threshold >= 1 ? $threshold : null;
    }
}
