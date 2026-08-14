<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace\Plausibility;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\ReportingPlausibilityRule;
use Pushery\Billing\ValueObjects\PlausibilityFinding;
use Pushery\Billing\ValueObjects\SellerReportingLine;

/**
 * A seller whose activity the package could not classify — and why that is a finding rather than a note.
 *
 * An unclassified line is not "probably not reportable". It is the reporting duty UNDECIDED, and both ways
 * of resolving it by default are offenses: filing a seller the duty does not cover discloses somebody's
 * income to a tax authority with no ground, and leaving out a seller it does cover is the omission the duty
 * exists to prevent. There is no safe guess, which is why {@see SellerReportingLine::reportable()} throws
 * rather than answering.
 *
 * It reads as the mildest entry in a report — one group, no amount attached, nothing visibly broken — and
 * it is the one that decides whether a filing is lawful.
 *
 * ## Why it is worth acknowledging at all
 *
 * Because the resolution is sometimes outside the package. A settlement written before archetypes existed
 * carries none and never will; the operator knows what was sold and can say so. What they cannot do is not
 * be asked.
 */
final readonly class UnclassifiedActivityRule implements ReportingPlausibilityRule
{
    public function key(): string
    {
        return 'unclassified_activity';
    }

    public function evaluate(array $reports, int $year, string $currency): array
    {
        $findings = [];

        foreach ($reports as $report) {
            $unclassified = array_values(array_filter($report->lines, static fn (SellerReportingLine $line): bool => ! $line->classified()));

            if ($unclassified === []) {
                continue;
            }

            $findings[] = new PlausibilityFinding(
                rule: $this->key(),
                subject: self::subjectOf($report->seller),
                detail: 'This seller has '.count($unclassified).' group(s) of activity the package could not '
                    .'classify, so whether they must be reported is undecided. Classify the products behind '
                    .'those settlements, or record the decision here — filing either way without one is a '
                    .'guess about somebody\'s tax affairs.',
            );
        }

        return $findings;
    }

    /** The seller's stored morph pair, which is the only identity the package has for them. */
    public static function subjectOf(Model $seller): string
    {
        // `getKey()` is mixed, and a model whose key is not scalar has no identity this can print. Rendered
        // as an empty half rather than guessed at: an operator reading `App\Seller#` at least sees that the
        // identity is missing, where an invented one would send them looking for a seller that is not there.
        $key = $seller->getKey();

        return $seller->getMorphClass().'#'.(is_scalar($key) ? (string) $key : '');
    }
}
