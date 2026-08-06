<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Carbon\CarbonImmutable;

/**
 * A rate change that has been announced and will apply on a date unless somebody stops it.
 *
 * ## The veto window, and why a late veto is not a quiet undo
 *
 * Inside the window, a veto prevents the change: nothing has been priced with it, so nothing needs saying.
 * After it, the change has been applying — invoices exist that used it — and reversing it silently would
 * leave documents in the world that no rate in the table explains. So a late veto produces a **correction**,
 * which is a visible act, rather than an edit to history, which is not.
 *
 * That distinction is the whole reason the window exists rather than a plain on/off. Without it the honest
 * answer to "can I still stop this" would have to be no.
 *
 * ## What is deliberately not here
 *
 * Nothing in this class touches a document. A rate change applies to future tax points; a document that
 * already exists carries the rate it was issued under, frozen, and is corrected if it was wrong — never
 * re-saddled. `RateChangeExclusions` states that list explicitly rather than leaving it to be inferred from
 * an absence.
 */
final readonly class ScheduledRateChange
{
    public function __construct(
        public string $country,
        public int $fromBps,
        public int $toBps,
        /** The tax point from which the new rate applies. */
        public CarbonImmutable $effectiveFrom,
        /** The last moment a veto prevents it rather than correcting it. */
        public CarbonImmutable $vetoUntil,
    ) {}

    /** Whether a veto raised at this moment prevents the change rather than correcting after the fact. */
    public function vetoPrevents(CarbonImmutable $raisedAt): bool
    {
        return $raisedAt->lessThanOrEqualTo($this->vetoUntil);
    }

    /**
     * Whether a veto raised at this moment leaves invoices behind that need correcting.
     *
     * Asked separately from `vetoPrevents()` on purpose. A caller that only asks "was it in time" learns
     * nothing about what to do when it was not — and the answer there is an action, not a shrug.
     */
    public function vetoNeedsCorrection(CarbonImmutable $raisedAt): bool
    {
        return ! $this->vetoPrevents($raisedAt) && $raisedAt->greaterThanOrEqualTo($this->effectiveFrom);
    }
}
