<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Illuminate\Database\Eloquent\Model;

/**
 * Our ledger and the provider's meter DISAGREE about how much usage a customer had.
 *
 * The two are separate sources of truth and they drift quietly: a report that was accepted locally but never
 * arrived, an event the provider rejected after the fact, a meter that stopped rating. Nothing throws — the
 * customer simply gets an invoice built on a different number than the one we showed them.
 *
 * This is the alarm. It carries both numbers so a listener can decide what the difference means (a handful
 * of units mid-flush is noise; a whole cycle missing is revenue that will not be collected).
 */
final readonly class UsageReconciliationDrift implements BillingDomainEvent
{
    public function __construct(
        public Model $owner,
        public string $meterKey,
        public string $period,
        /** What WE believe we successfully reported (already netted of prepaid units). */
        public int $reported,
        /** What the PROVIDER says it actually recorded. */
        public int $recorded,
    ) {}

    /** Provider minus ours: negative means usage never arrived, positive means it has more than we sent. */
    public function delta(): int
    {
        return $this->recorded - $this->reported;
    }
}
