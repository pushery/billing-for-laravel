<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace\Plausibility;

use Pushery\Billing\Contracts\ReportingPlausibilityRule;
use Pushery\Billing\ValueObjects\PlausibilityFinding;

/**
 * One seller, one row — the rule no single row can break.
 *
 * A duplicate is the failure mode that survives every per-row check, because both rows are correct. Their
 * figures are right, their fields are complete, their identifiers hold; the period is simply reported twice
 * for that seller, which doubles the income attributed to them at the receiving end.
 *
 * The roster is built with `distinct()` on the stored morph pair, so this should be impossible — and that
 * is exactly why it is checked. A rule whose job is to be silent tells you when a change upstream stopped
 * being true, and this one is cheap: the roster is already in memory.
 *
 * Two DIFFERENT sellers who happen to resolve to the same record — the same morph pair reached through two
 * classes, say after a morph-map rename — land here too, which is the same problem seen from the other end.
 */
final readonly class DuplicateSellerRule implements ReportingPlausibilityRule
{
    public function key(): string
    {
        return 'seller_reported_twice';
    }

    public function evaluate(array $reports, int $year, string $currency): array
    {
        $seen = [];

        foreach ($reports as $report) {
            $subject = UnclassifiedActivityRule::subjectOf($report->seller);
            $seen[$subject] = ($seen[$subject] ?? 0) + 1;
        }

        $findings = [];

        foreach ($seen as $subject => $count) {
            if ($count < 2) {
                continue;
            }

            $findings[] = new PlausibilityFinding(
                rule: $this->key(),
                subject: $subject,
                detail: 'This seller appears '.$count.' times in the period. Both rows can be individually '
                    .'correct and the filing still doubles their income, which is why no per-row check can '
                    .'see it. Resolve the roster before filing.',
            );
        }

        return $findings;
    }
}
