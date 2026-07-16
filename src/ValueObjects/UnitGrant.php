<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

/**
 * What an add-on gives an owner when it grants USAGE UNITS instead of money: how many units, of which
 * meter. Configured as `billing.addons.<key>.grants = ['meter' => 'emails', 'units' => 1000]`.
 *
 * An add-on grants EITHER units or money credit, never both: the two are different promises, and paying
 * one purchase out twice is not generosity, it is a bug.
 */
final readonly class UnitGrant
{
    public function __construct(
        public string $meterKey,
        public int $units,
    ) {}

    /**
     * The units that correspond to a PARTIAL money refund of the purchase — refunding half of what was
     * paid takes back half of what was granted. Rounded to the nearest unit; a refund of the whole purchase
     * always maps back to the whole grant.
     */
    public function unitsFor(int $refundedMinor, int $purchaseMinor): int
    {
        if ($refundedMinor <= 0 || $purchaseMinor <= 0) {
            return 0;
        }

        if ($refundedMinor >= $purchaseMinor) {
            return $this->units;
        }

        return (int) round($this->units * $refundedMinor / $purchaseMinor);
    }
}
