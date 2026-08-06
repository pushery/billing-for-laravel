<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One seller's whole reporting period: what they sold, whether it is reportable, and the four quarters.
 *
 * ## Why the verdict is per LINE and not per seller
 *
 * A reporting rule branches on WHAT was sold. There is no small-scale relief for commissioned work, and a
 * thousand standardized downloads are not reportable however much they came to — so a seller who did both
 * in one year has two answers, and a single verdict would apply whichever the caller happened to look at
 * first to all of it.
 *
 * The field basis turns on the same thing: `ReportingProfile::fieldsFor(..., reportable:)` asks it, so
 * flattening the verdict here would flatten which fields the seller's record legally needs.
 *
 * ## What is deliberately absent
 *
 * The seller's own record — their name, address, the identifiers a statute names. The package holds the
 * field catalog and the completeness rule; the VALUES belong to the consuming application, which is where
 * seller master data lives. A caller joins their own record to this.
 */
final readonly class SellerPeriodReport
{
    /**
     * @param  list<SellerReportingLine>  $lines  one per kind of thing sold, the unclassified group last
     * @param  array<int, SellerQuarterFigures>  $quarters  keyed 1..4, always all four
     */
    public function __construct(
        public Model $seller,
        public array $lines,
        public array $quarters,
    ) {}

    /**
     * Whether ANY line obliges a report.
     *
     * Refuses on an unclassified line rather than answering false, exactly as the line itself does: false is
     * a decision, and a line nobody classified has not been decided. Answering it here would let a whole
     * seller be left out of a filing on the strength of a group the documents could not describe — and
     * leaving out a seller the statute covers is the offense the duty exists to prevent.
     *
     * @throws LogicException when any line carries no classification — the same refusal the line itself raises
     */
    public function reportable(): bool
    {
        $reportable = false;

        foreach ($this->lines as $line) {
            // Asked of EVERY line rather than short-circuiting on the first true one. The refusal is the
            // point: a seller with one reportable line and one unclassified group is not "reportable and
            // done" -- the unclassified part still has to be resolved before anything is filed.
            $reportable = $line->reportable() || $reportable;
        }

        return $reportable;
    }
}
