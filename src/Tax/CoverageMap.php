<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Pushery\Billing\Enums\RateCoverage;
use Pushery\Billing\Exceptions\UnknownTaxCountry;

/**
 * Which countries this installation can answer for, and which it must refuse.
 *
 * ## The distinction that carries the whole class
 *
 * A rate of 0% for an unclassified country is indistinguishable from a relief. The invoice says zero, the
 * return says zero, and nothing records that the zero was a gap rather than a rule. So a country outside the
 * tax area — a decision somebody made — and a country nobody has looked at are kept apart, and only the
 * first may produce a zero.
 *
 * ## Why the extension seam is a binding, not a list to grow
 *
 * The owner's rule for this package is explicit: the EU must be perfect, trade out of the EU is built,
 * everything else is built as far as it is genuinely known — and beyond that a developer extends it
 * themselves rather than finding a plausible guess already in place. This map is therefore replaceable
 * wholesale by a jurisdiction profile. Growing the shipped list to cover the world would be the opposite of
 * that instruction: it would put answers in the package that nobody here can defend.
 */
final readonly class CoverageMap
{
    /**
     * @param  list<string>  $covered  countries a rate is known for
     * @param  list<string>  $deliberatelyUntaxed  countries classified as outside the tax area
     */
    public function __construct(
        private array $covered,
        private array $deliberatelyUntaxed = [],
    ) {}

    public function for(string $country): RateCoverage
    {
        $code = strtoupper(trim($country));

        if (in_array($code, array_map(strtoupper(...), $this->covered), true)) {
            return RateCoverage::Covered;
        }

        if (in_array($code, array_map(strtoupper(...), $this->deliberatelyUntaxed), true)) {
            return RateCoverage::DeliberatelyUntaxed;
        }

        return RateCoverage::Unknown;
    }

    /**
     * Refuse unless this country has an answer somebody stands behind.
     *
     * Called before pricing rather than after, so the refusal happens while there is still nothing to
     * un-issue. The message names the seam, because "unknown country" without a way forward is how somebody
     * ends up adding a plausible rate to make the error stop.
     *
     * @throws UnknownTaxCountry
     */
    public function assertKnown(string $country): RateCoverage
    {
        $coverage = $this->for($country);

        if ($coverage === RateCoverage::Unknown) {
            throw UnknownTaxCountry::unclassified($country);
        }

        return $coverage;
    }

    /**
     * Everything this installation deliberately does not tax — for a document that has to say so.
     *
     * A coverage list that names only its hits reads as completeness. This is the other half, and it is the
     * half a reader needs in order to know what the silence means.
     *
     * @return list<string>
     */
    public function untaxed(): array
    {
        return array_map(strtoupper(...), $this->deliberatelyUntaxed);
    }
}
