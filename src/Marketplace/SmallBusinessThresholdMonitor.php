<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\AnnualEarningsCounter;
use Pushery\Billing\Enums\SettlementState;
use Pushery\Billing\Models\MerchantCharge;

/**
 * The German § 19 evaluation: it reads the three limits from config (never a code literal — they are the
 * law's numbers and change with it) and decides whether a creator has broken one. Only the EVALUATION lives
 * here; the counting is the jurisdiction-neutral {@see AnnualEarningsCounter}, which knows no limit at all.
 *
 * Two limits with different shapes. The current-year limit (or, in a creator's founding year, the immediate
 * limit) is broken FROM a single transaction: the exemption falls away at the charge that carries the running
 * settled total over the line, and that charge is itself taxable, so this reports its identity, not merely
 * "over". The prior-year limit is a yearly verdict on last year's total, with no single breaking transaction.
 *
 * The founding year switches which current limit applies — with NO pro-rata twelfths (abolished 2025). It is
 * passed in rather than read here, so the evaluation stays a pure function of (earnings, limits) that a test
 * can drive without a status ledger; the caller reads the creator's founding year and hands it over.
 *
 * The running total for the break is GROSS of reversals: a later refund does not un-break a break that
 * already happened — that is a dated status correction, not an erasure.
 */
final readonly class SmallBusinessThresholdMonitor
{
    public function __construct(
        private Repository $config,
        private AnnualEarningsCounter $counter,
    ) {}

    /**
     * The transaction that carried the creator's current-year settled total over the applicable limit, or
     * null if it stayed within. A null or non-integer limit in config leaves the monitor silent rather than
     * inventing a break.
     */
    public function currentYearBreach(Model $creator, string $currency, int $year, ?int $foundingYear): ?SmallBusinessThresholdBreach
    {
        $limit = $this->config->get($foundingYear === $year
            ? 'billing.tax_small_business.founding_year_limit'
            : 'billing.tax_small_business.current_year_limit');

        if (! is_int($limit)) {
            return null;
        }

        $start = sprintf('%04d-01-01 00:00:00', $year);
        $end = sprintf('%04d-01-01 00:00:00', $year + 1);

        $charges = MerchantCharge::query()
            ->where('merchant_type', $creator->getMorphClass())
            ->where('merchant_id', $creator->getKey())
            ->where('currency', strtoupper($currency))
            ->where('settlement_state', SettlementState::Settled->value)
            ->where('settled_at', '>=', $start)
            ->where('settled_at', '<', $end)
            ->orderBy('settled_at')
            ->orderBy('id')
            // Everything `payoutNet()` reads, not just the total. Selecting a narrower set would leave the
            // model without the rate and the commission and it would answer from nulls -- a figure that is
            // wrong in the same direction as the defect this replaced, and just as quiet.
            ->get(['charge_reference', 'gross_minor', 'fee_minor', 'commission_tax_bps', 'currency', 'settled_at']);

        $cumulative = 0;

        foreach ($charges as $charge) {
            // THE SAME BASIS THE COUNTER USES. This walked `net_minor` -- what reached the creator, the
            // buyer's tax included -- while the counter beside it was corrected to the creator's supply. The
            // two then decided the same flip from figures about a fifth apart, and this is the half that
            // issues the verdict: on the package's own example it broke the founding-year limit at sale 230
            // where the supply basis breaks it at 278, and the 95% warning read off the corrected counter
            // landed 34 sales AFTER the breach it is meant to precede.
            //
            // Early in exactly the expensive direction: a creator is declared out of the small-business
            // regime while still inside it, and owes a tax they do not yet owe on every settlement after.
            $cumulative += $charge->payoutNet()->minorUnits;
            $settledAt = $charge->settled_at;

            if ($settledAt !== null && $cumulative > $limit) {
                return new SmallBusinessThresholdBreach($charge->charge_reference, $settledAt, $limit, $cumulative);
            }
        }

        return null;
    }

    /**
     * Whether the ledger holds ANY settled earnings for this seller in the years a verdict reads.
     *
     * ## Why a verdict needs this and cannot infer it
     *
     * Both methods above answer "no" on an empty table, and "no" is indistinguishable from a seller who
     * genuinely sold nothing. For most questions that does not matter. For this one it does: the answer feeds
     * a TAX decision, and the direction it fails in is the expensive one — a seller who has long passed the
     * threshold keeps being billed without tax, every document is well-formed, every return adds up, and the
     * only trace is a zero that looks like an answer.
     *
     * That was not hypothetical. Nothing wrote this table in production at all, so every seller read as
     * relieved, permanently. The writer exists now; this exists so a verdict can still say what it is
     * standing on rather than presenting an absence as a finding.
     *
     * It deliberately does NOT decide anything. A single-seller install has an empty table for the perfectly
     * good reason that it has no merchants, and refusing to answer there would break an install that never
     * had the question. The caller that states tax is the one with the context to act on it.
     */
    public function hasObservedEarnings(Model $creator, string $currency, int $year): bool
    {
        return MerchantCharge::query()
            ->where('merchant_type', $creator->getMorphClass())
            ->where('merchant_id', $creator->getKey())
            ->where('currency', strtoupper($currency))
            ->where('settlement_state', SettlementState::Settled->value)
            // Both years a verdict reads: this one for the running total, last one for the prior-year limit.
            ->where('settled_at', '>=', sprintf('%04d-01-01 00:00:00', $year - 1))
            ->where('settled_at', '<', sprintf('%04d-01-01 00:00:00', $year + 1))
            ->exists();
    }

    /**
     * The highest configured warning level this creator has already reached, or null for none.
     *
     * ## Why a warning at all, when there is already a breach check
     *
     * Because crossing the limit is too late to start reacting to. A creator who becomes standard rated
     * has to register, and registration takes weeks — so the first day they owe tax is a day they cannot
     * yet charge it correctly. The same reasoning the US activation share already ships with: waiting for
     * a limit to be crossed is waiting too long.
     *
     * ## Highest first, and only one
     *
     * A creator at 96% has passed both 80% and 95%; reporting both says nothing extra and makes the
     * report longer the closer somebody gets, which is backwards. The highest level reached is the whole
     * of the news.
     *
     * Returns null when nothing is configured, when the limit is not a number, or when no level has been
     * reached — the same silence the breach check keeps rather than inventing a verdict from a missing
     * value.
     *
     * @return ?float the level as its configured fraction, e.g. 0.95
     */
    public function approachingLevel(Model $creator, string $currency, int $year, ?int $foundingYear): ?float
    {
        $limit = $this->config->get($foundingYear === $year
            ? 'billing.tax_small_business.founding_year_limit'
            : 'billing.tax_small_business.current_year_limit');

        $levels = $this->config->get('billing.tax_small_business.warning_levels', []);

        if (! is_int($limit) || $limit <= 0 || ! is_array($levels) || $levels === []) {
            return null;
        }

        $earned = $this->counter->earnedIn($creator, $currency, $year)->minorUnits;

        $reached = array_values(array_filter(
            array_map(static fn (mixed $level): float => is_numeric($level) ? (float) $level : 0.0, $levels),
            static fn (float $level): bool => $level > 0.0 && $earned >= (int) round($limit * $level),
        ));

        if ($reached === []) {
            return null;
        }

        return max($reached);
    }

    /**
     * Whether last year's settled earnings exceeded the prior-year limit — the verdict that makes a creator
     * standard-rated from January 1. A null or non-integer limit in config reads as not exceeded.
     */
    public function previousYearExceeded(Model $creator, string $currency, int $year): bool
    {
        $limit = $this->config->get('billing.tax_small_business.previous_year_limit');

        return is_int($limit) && $this->counter->earnedIn($creator, $currency, $year - 1)->minorUnits > $limit;
    }
}
