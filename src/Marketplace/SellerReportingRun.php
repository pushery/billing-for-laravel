<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\ClassifiesReportability;
use Pushery\Billing\Enums\TaxArchetype;
use Pushery\Billing\ValueObjects\CountingPeriod;
use Pushery\Billing\ValueObjects\SellerActivity;
use Pushery\Billing\ValueObjects\SellerReportingLine;

/**
 * What a seller's period looks like to a reporting rule: one line per kind of thing they sold, each with a
 * verdict and the figures that produced it.
 *
 * This is the caller `ClassifiesReportability::classify()` never had. The rule was bound, correct and
 * unreachable — a classification nobody asked for — which is the shape this package has found in itself
 * repeatedly and which a verdict test cannot see, because the rule passes every test it has while nothing
 * runs it.
 *
 * ## One line per archetype, not one per seller
 *
 * The rule branches on WHAT was sold: there is no small-scale relief for commissioned work, and a thousand
 * standardized downloads are not reportable however much they came to. A seller who sold both in one
 * quarter has two answers, and a single figure handed to the rule would get one of them — whichever the
 * caller happened to name — for all of it.
 *
 * ## Nothing is decided about what the documents cannot say
 *
 * Settlements carrying no archetype come back as their own line with `classified: false` and NO verdict.
 * That is the whole safety of this class. Two real things land there: a settlement from before the
 * classification could be recorded, and a COLLECTIVE settlement, which covers many transactions and has no
 * single archetype to carry. Deciding either way about them would be a guess in a place where both
 * directions are violations — filing a seller the statute leaves out hands an authority personal data with
 * no basis, and leaving out one it covers is the offense the duty exists to prevent.
 *
 * So the line is returned, unjudged, for a caller to resolve from their own catalog. It is deliberately
 * impossible to consume this without seeing it.
 *
 * ## What it does not do
 *
 * It does not file anything, does not decide the period, and holds no jurisdiction of its own — the rule
 * comes from the bound profile, and a consumer elsewhere gets their own answer by binding theirs.
 */
final readonly class SellerReportingRun
{
    public function __construct(
        private SettlementGrossInflowCounter $inflow,
        private ClassifiesReportability $rule,
    ) {}

    /**
     * The seller's period, line by line.
     *
     * Ordered by the archetype's own value with the unclassified line last, so two runs over the same data
     * produce the same list — a report whose row order depends on how the database felt is one nobody can
     * diff against last quarter's.
     *
     * @return list<SellerReportingLine>
     */
    public function linesFor(Model $seller, string $currency, CountingPeriod $period): array
    {
        $activities = $this->activitiesFor($seller, $currency, $period);

        $lines = [];
        $unclassified = null;

        foreach ($activities as $activity) {
            // The unclassified group is built the same way and then NOT judged. Asking the rule anyway would
            // get an answer — `standardized`, because an absent archetype is not goods and not a commission
            // — and that answer would be indistinguishable from one the documents actually supported.
            $line = $activity->archetype instanceof TaxArchetype
                ? new SellerReportingLine($activity, $this->rule->classify($activity))
                : new SellerReportingLine($activity, null);

            if (! $activity->archetype instanceof TaxArchetype) {
                $unclassified = $line;

                continue;
            }

            $lines[] = $line;
        }

        return $unclassified instanceof SellerReportingLine ? [...$lines, $unclassified] : $lines;
    }

    /**
     * The period's groups, merged onto the archetype whose consequences actually apply.
     *
     * ## Why the counter's split is not the rule's split
     *
     * The counter groups by what the DOCUMENTS say — the archetype and, for a voluntary payment, the thing
     * it was paid alongside. That is the right split for a figure, because it keeps the reference visible.
     * It is the wrong split for the RULE, and the difference is not cosmetic: a tip that delegates to goods
     * and the goods themselves are, to the rule, one activity. Handing it two lines makes it measure each
     * fragment on its own.
     *
     * That matters because one of the rule's branches is a THRESHOLD. The small-scale relief asks whether a
     * seller stayed under a sales count AND an amount — and it is asked per line. A seller who is over both
     * edges, split into two lines that are each under them, comes back exempt twice and is never filed.
     * Under-reporting: the direction the statute sanctions.
     *
     * Measured rather than reasoned about: 28 goods settlements at 190,008 plus five tips on goods at
     * 15,000 is 33 sales and 205,008 — over both shipped edges (30 / 200,000). Split, both fragments read
     * as within the relief and the seller disappears from the return.
     *
     * So the merge happens HERE and not in the counter. The counter would need to know which archetypes
     * delegate, which is a jurisdiction's answer living in the taxonomy — and putting it there would make
     * a figure depend on a rule, which is the coupling this package keeps taking apart.
     *
     * ## What the merged line carries
     *
     * The EFFECTIVE archetype and no reference. Once a tip has been resolved onto what it accompanied, the
     * reference has done its work: keeping it would state that the whole merged line was paid alongside
     * something, which is untrue of the part that was the sale itself. Two tips with different references
     * merge into their two different targets, which is exactly what delegation means.
     *
     * Groups with no archetype merge together too, and that is a fix rather than a side effect: they used
     * to be held in one variable and the second one silently REPLACED the first, so a period with an
     * unclassified tip and an unclassified sale reported one of them and the lines stopped adding up to the
     * period's own total.
     *
     * @return list<SellerActivity>
     */
    private function activitiesFor(Model $seller, string $currency, CountingPeriod $period): array
    {
        $groups = $this->inflow->countedInByArchetype($seller, $currency, $period);

        ksort($groups);

        /** @var array<string, SellerActivity> $merged */
        $merged = [];

        foreach ($groups as $group) {
            $resolved = new SellerActivity(
                archetype: $group['archetype'],
                salesCount: $group['transactions'],
                compensation: $group['gross'],
                soldAlongside: $group['soldAlongside'],
            )->effectiveArchetype();

            $key = $resolved instanceof TaxArchetype ? $resolved->value : 'unclassified';

            $merged[$key] = isset($merged[$key])
                ? new SellerActivity(
                    archetype: $resolved,
                    salesCount: $merged[$key]->salesCount + $group['transactions'],
                    compensation: $merged[$key]->compensation->plus($group['gross']),
                )
                : new SellerActivity(
                    archetype: $resolved,
                    salesCount: $group['transactions'],
                    compensation: $group['gross'],
                );
        }

        ksort($merged);

        return array_values($merged);
    }
}
