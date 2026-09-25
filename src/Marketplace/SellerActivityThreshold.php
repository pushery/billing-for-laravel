<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Preflight\Profiles\GermanReportingProfile;

/**
 * When a platform asks a seller to declare their standing. A platform's OWN setting, and nothing more.
 *
 * It fires when EITHER measure is reached and it is meant to be early: asking somebody a question a little
 * sooner than strictly necessary costs nothing, so the operator is free to move it wherever they like.
 *
 * ## What deliberately does NOT live here, and why it used to
 *
 * Whether a seller's data is exempt from the REPORTING DUTY is a different question with a different
 * authority behind it — set by law, not by preference — and it is answered in one place only, the
 * jurisdiction's reporting profile ({@see GermanReportingProfile}).
 *
 * This class used to answer it too, in an `isExemptFromReporting()` that nothing called. The duplication
 * was the visible half of the problem; the coupling was the dangerous half. That method read the SAME two
 * config keys as the declaration below — so a platform asking for declarations earlier would have moved the
 * statutory exemption with the same switch. In the over-reporting direction, silently, from a class whose
 * own docblock warned against exactly that. Reporting data that need not be reported is an incorrect report
 * in its own right and a data protection breach besides, so that direction is not the cautious one.
 *
 * With the shipped defaults the two questions agree at the money figure: the declaration fires at it, and
 * the statutory exemption, which only covers LESS than the figure, no longer holds there. That agreement is
 * where the German statute draws its line, not a shared rule, which is why they stay in separate classes
 * reading separate keys. Moving one must never move the other. Pinned by DeMinimisBoundaryHasOneHomeTest.
 */
final readonly class SellerActivityThreshold
{
    public function __construct(private Repository $config) {}

    /**
     * Whether this seller must now declare their standing.
     *
     * EITHER measure is enough. The test is activity, not intent: somebody who sells regularly is trading
     * whether or not they make anything on it, so a threshold built around "are they trying to profit"
     * would ask the wrong question entirely.
     */
    public function requiresStatusDeclaration(int $sales, int $proceedsMinor): bool
    {
        if ($this->reaches($sales, $this->salesThreshold(), $this->config->get('billing.marketplace.seller_activity.sales_comparison'))) {
            return true;
        }

        return $this->reaches($proceedsMinor, $this->proceedsThresholdMinor(), $this->config->get('billing.marketplace.seller_activity.proceeds_comparison'));
    }

    /** How many sales in the period count as trading. */
    public function salesThreshold(): int
    {
        $value = $this->config->get('billing.marketplace.seller_activity.sales_threshold', 30);

        return is_int($value) && $value > 0 ? $value : 30;
    }

    /** How much in the period counts as trading, in minor units. */
    public function proceedsThresholdMinor(): int
    {
        $value = $this->config->get('billing.marketplace.seller_activity.proceeds_threshold_minor', 200_000);

        return is_int($value) && $value > 0 ? $value : 200_000;
    }

    /**
     * Whether a figure has reached its threshold, by the operator configured for it.
     *
     * `>=` by default: reaching the figure is enough. `>` asks for more than it. Any other value falls back to
     * `>=`, the earlier of the two, because this is a question meant to be asked early. It lives here and
     * nowhere else on purpose: the reporting exemption compares its own figures by its own rule.
     */
    private function reaches(int $figure, int $threshold, mixed $comparison): bool
    {
        return $comparison === '>' ? $figure > $threshold : $figure >= $threshold;
    }
}
