<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Illuminate\Contracts\Config\Repository;

/**
 * Two thresholds that read almost identically and must never be computed together.
 *
 * One asks a seller to declare their standing once they are clearly trading. It fires when EITHER measure
 * is reached, it is a platform's own setting, and it is meant to be early: asking somebody a question a
 * little sooner than strictly necessary costs nothing.
 *
 * The other decides whether a seller's data is exempt from a reporting duty. It holds only while BOTH
 * measures stay under, and its boundary is set by law rather than by preference. A step too early there is
 * an over-report — reporting data that need not be reported is itself an incorrect report, and a privacy
 * problem besides.
 *
 * At exactly the money figure the two disagree: the declaration fires, the exemption still holds. That is
 * correct, and it is why they live in separate methods that share no helper. Anybody "harmonizing" them
 * would move the legal boundary to match a preference, and the direction that harmonization naturally
 * takes is the one that over-reports.
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

    /**
     * Whether this seller stays outside the reporting duty.
     *
     * BOTH measures must stay under, and the money comparison is inclusive: at exactly the figure the
     * exemption still holds. Deliberately NOT expressed in terms of the method above — the two answer
     * different questions and their boundaries are set by different authorities.
     */
    public function isExemptFromReporting(int $sales, int $proceedsMinor): bool
    {
        return $sales < $this->salesThreshold() && $proceedsMinor <= $this->proceedsThresholdMinor();
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
