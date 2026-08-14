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
 * The two questions still meet at the money figure and still answer differently there — the declaration
 * fires at it, the statutory exemption holds at it. Keeping them in separate classes reading separate keys
 * is what makes that a design rather than a coincidence. Pinned by DeMinimisBoundaryHasOneHomeTest.
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
        if ($sales >= $this->salesThreshold()) {
            return true;
        }

        return $proceedsMinor >= $this->proceedsThresholdMinor();
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
}
