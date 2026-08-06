<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Enums\CreatorTaxStatus;
use Pushery\Billing\Enums\CreatorTaxStatusSource;

/**
 * The asymmetric automatic status flip: a platform count that reaches a small-business limit flips the
 * creator to standard rating; a count UNDER the limit never flips them back.
 *
 * The asymmetry is not a preference — it follows from what the platform count is. It sees only the turnover
 * that ran through this platform, so it is a LOWER BOUND of the creator's real total. When the lower bound
 * breaks the limit, the limit is SURELY broken (there can only be more, never less), and continuing to issue
 * tax-free self-billed invoices would be knowingly wrong — so the flip is mandatory. When the lower bound
 * stays under, NOTHING is proven (external turnover is invisible here), so an automatic flip back, or an
 * automatic confirmation of the small-business status, is a fallacy the system must not commit.
 *
 * A flip writes a new status line (source = auto_flip, no attestation clock) at the RIGHT effective moment —
 * the breaking transaction's own time for an intra-year break, January 1 for a prior-year one — never the
 * day's start and never the job's run time. The ledger fires its status-changed event, which the creator
 * notification listens on. Running it again over the same count is a no-op: the creator is already standard-
 * rated at that moment, so no second line and no second notification are produced.
 *
 * It flips only a creator who is currently a small business, and only forward — there is no path here from
 * standard-rated back to small-business; that return is a self-declaration with its own effective date.
 */
final readonly class SmallBusinessAutoFlip
{
    public function __construct(
        private Repository $config,
        private SmallBusinessThresholdMonitor $monitor,
        private CreatorTaxStatusLedger $ledger,
    ) {}

    public function reconcile(Model $creator, string $currency, int $year, ?int $foundingYear): void
    {
        // Only strictly-enabled flips: a missing or non-boolean switch fails toward NOT changing a creator's
        // tax standing on its own.
        if ($this->config->get('billing.tax_small_business.auto_flip_enabled', true) !== true) {
            return;
        }

        // Prior-year over the limit → standard-rated from January 1 of this year. Evaluated first, so that when
        // both triggers apply the earlier (January 1) date wins and the intra-year one becomes a no-op.
        if ($this->monitor->previousYearExceeded($creator, $currency, $year)) {
            $this->flip($creator, new CarbonImmutable(sprintf('%04d-01-01 00:00:00', $year), 'UTC'), 'year:'.($year - 1));
        }

        // The transaction that broke the current-year (or founding-year) limit → flip at that transaction's
        // own moment; everything before it stays exempt.
        $breach = $this->monitor->currentYearBreach($creator, $currency, $year, $foundingYear);

        if ($breach instanceof SmallBusinessThresholdBreach) {
            $this->flip($creator, CarbonImmutable::instance($breach->brokenAt), $breach->chargeReference);
        }
    }

    private function flip(Model $creator, CarbonImmutable $effectiveFrom, string $evidenceRef): void
    {
        if (! $this->ledger->statusAt($creator, $effectiveFrom)->reliesOnSizeRelief()) {
            return;
        }

        $this->ledger->record(
            $creator,
            CreatorTaxStatus::DomesticStandardRated,
            $effectiveFrom,
            CreatorTaxStatusSource::AutoFlip,
            evidenceRef: $evidenceRef,
        );
    }
}
