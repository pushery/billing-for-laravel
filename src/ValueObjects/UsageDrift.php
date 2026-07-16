<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Illuminate\Database\Eloquent\Model;

/**
 * A disagreement between our ledger and the provider's meter for one owner, meter and cycle.
 *
 * Both numbers are already netted of prepaid units — they are what should have crossed the wire, so a
 * non-zero delta is a genuine discrepancy and not a prepaid customer being double-counted. This is the
 * return shape of a reconcile run: a machine-readable row for a command to print and an event to carry.
 */
final readonly class UsageDrift
{
    public function __construct(
        public Model $owner,
        public string $meterKey,
        public string $period,
        /** What WE believe we successfully reported. */
        public int $reported,
        /** What the PROVIDER says it actually recorded. */
        public int $recorded,
    ) {}

    /** Provider minus ours: negative means usage never arrived, positive means it holds more than we sent. */
    public function delta(): int
    {
        return $this->recorded - $this->reported;
    }
}
