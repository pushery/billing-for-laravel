<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Contracts\CrossBorderSalesCounter;
use Pushery\Billing\Contracts\SuppliesDistanceSaleThreshold;
use Pushery\Billing\Enums\PlaceOfSupplyRule;
use Pushery\Billing\Exceptions\InvalidBillingConfig;
use Pushery\Billing\Preflight\CheckpointRegistry;

/**
 * Where a cross-border consumer sale is taxed: at the seller, or at the buyer.
 *
 * The two answers are the enum's own: taxed where the buyer is, or where the seller is. Two ways lead to the
 * buyer's country, and a platform is on exactly one of them. Either the operator has
 * declared to their revenue office that the small-turnover exemption does not apply to them — after which
 * there is one rule for the rest of time — or they have not, and a running total decides sale by sale until
 * it passes a limit and stays past it.
 *
 * ## The package never declares the waiver, and never assumes it away either
 *
 * The declaration is a statement of intent an operator makes to an authority, binding for years. This cannot
 * make it and cannot prove it, so it is configuration. What it CAN do is refuse to guess: with no monitor
 * configured and no waiver declared, a cross-border sale is taxed at the destination anyway. That is the
 * expensive direction and the safe one — charging the seller's rate where the buyer's was owed
 * under-declares in a country nobody is registered in, and surfaces as an assessment years later.
 *
 * ## The crossing sale is already over the line
 *
 * The sale that takes the total past the limit is itself taxed at the destination — it is not the last one
 * under the old rule but the first one under the new. An implementation that switched from the NEXT sale
 * would mis-tax exactly one transaction per seller per year, in the year an auditor looks hardest.
 *
 * ## One direction only
 *
 * Passing the limit is permanent for the year and the year after, because the previous year's turnover
 * counts too. There is no automatic way back: a seller whose turnover falls is still bound until the rule
 * lets them out, and a monitor that flipped back on its own would decide that for them.
 *
 * Nothing national lives here. The limit is a number the jurisdiction profile supplies; without a profile
 * there is no limit, no monitor, and no behavior at all.
 */
final readonly class DistanceSaleThresholdMonitor
{
    public function __construct(
        private Repository $config,
        private CrossBorderSalesCounter $counter,
        private CheckpointRegistry $profiles,
    ) {}

    /**
     * Where a sale is taxed this year, with the limit taken from the active jurisdiction profile.
     *
     * This is the entry point anything issuing a document should use. {@see self::placeFor()} takes the
     * limit as an argument, which is right for a test and wrong for a caller: a caller that has to supply
     * the number is a caller that can supply the wrong one, and a jurisdiction's limit is exactly the sort
     * of number nobody re-checks once it is typed somewhere.
     *
     * No profile, or a profile with no such limit, means there is nothing to watch — and the safe direction
     * is the destination, never the seller's own rate.
     */
    public function rule(int $year, string $currency): PlaceOfSupplyRule
    {
        return $this->placeFor($year, $currency, $this->thresholdMinor());
    }

    /** The active profile's limit, or zero where there is none to watch. */
    public function thresholdMinor(): int
    {
        $profile = $this->profiles->profile();

        return $profile instanceof SuppliesDistanceSaleThreshold ? $profile->distanceSaleThresholdMinor() : 0;
    }

    /**
     * The day the active profile's limit was known to be correct, or null where no profile supplies one.
     *
     * The exact mirror of `thresholdMinor()`, and the reader the contract was written for: its docblock says
     * the date exists "so its age can be reported rather than assumed", and until now nothing asked. A
     * promise with no reader reads exactly like a promise being kept.
     *
     * Null rather than a shipped fallback, and that is the difference from the union membership beside it in
     * the doctor: this package ships no distance-sale limit of its own. The number lives in a consumer's
     * profile, hard-coded there, and is precisely the sort of figure that goes on working while it goes out
     * of date — a legislator moves it, the monitor keeps computing, and the result stays plausible. Too high
     * and the operator books at the destination too late; too low and too early. Either way it is a tax
     * question, not a display one.
     */
    public function thresholdValidFrom(): ?CarbonInterface
    {
        $profile = $this->profiles->profile();

        return $profile instanceof SuppliesDistanceSaleThreshold ? $profile->distanceSaleThresholdValidFrom() : null;
    }

    /**
     * Where a cross-border consumer sale is taxed, given what has been sold this year and an explicit limit.
     *
     * @param  int  $limitMinor  the jurisdiction's threshold, in minor units
     */
    public function placeFor(int $year, string $currency, int $limitMinor): PlaceOfSupplyRule
    {
        if ($this->waived() || $limitMinor <= 0) {
            return PlaceOfSupplyRule::Destination;
        }

        $exceededBefore = $this->counter->crossBorderNetIn($year - 1, $currency)->minorUnits > $limitMinor;

        if ($exceededBefore) {
            return PlaceOfSupplyRule::Destination;
        }

        return $this->counter->firstSaleAbove($year, $currency, $limitMinor) === null
            ? PlaceOfSupplyRule::Domestic
            : PlaceOfSupplyRule::Destination;
    }

    /**
     * The sale at which this year crossed the limit, or null when it has not.
     *
     * @return ?array{reference: string, cumulativeMinor: int}
     */
    public function crossingSale(int $year, string $currency, int $limitMinor): ?array
    {
        if ($this->waived() || $limitMinor <= 0) {
            return null;
        }

        return $this->counter->firstSaleAbove($year, $currency, $limitMinor);
    }

    /**
     * How close this year is to the limit, against the configured warning levels.
     *
     * Warnings exist so a registration does not begin on the day it is already needed. The levels are
     * configuration because how much notice an operator wants is their own answer.
     *
     * @return list<float> the levels already reached, lowest first
     */
    public function warningsReached(int $year, string $currency, int $limitMinor): array
    {
        if ($this->waived() || $limitMinor <= 0) {
            return [];
        }

        $sold = $this->counter->crossBorderNetIn($year, $currency)->minorUnits;

        return array_values(array_filter(
            $this->warningLevels(),
            fn (float $level): bool => $sold >= (int) round($limitMinor * $level),
        ));
    }

    /**
     * Whether the operator has declared that the threshold does not apply to them.
     *
     * The default is TRUE and it does not mean the package declared anything. It means no origin-country
     * fallback is applied — the direction that never under-charges. An operator who wants the threshold
     * watched turns it off and configures a counter.
     */
    public function waived(): bool
    {
        $waived = $this->config->get('billing.tax_oss.threshold_waived');

        return ! is_bool($waived) || $waived;
    }

    /**
     * Refuse a waiver withdrawn inside its binding period.
     *
     * The declaration binds for a stated number of years, so turning it off early is not a configuration
     * choice — it is a claim about the operator's own filings that the revenue office would disagree with.
     * Failing at boot is the only place that disagreement is cheap: silently reverting to origin taxation
     * would under-declare in every destination country until somebody noticed.
     *
     * @param  int  $bindingYears  how long the jurisdiction binds a declaration
     */
    public function assertBindingHonored(int $currentYear, int $bindingYears): void
    {
        if ($this->waived()) {
            return;
        }

        $since = $this->config->get('billing.tax_oss.waived_since');

        if (! is_string($since) || $since === '') {
            return;
        }

        $waivedYear = (int) substr($since, 0, 4);

        if ($currentYear < $waivedYear + $bindingYears) {
            throw InvalidBillingConfig::forKey(
                'billing.tax_oss.threshold_waived',
                sprintf(
                    'to stay true until %d. A waiver declared in %d binds for %d calendar years, so '
                    .'withdrawing it now contradicts a declaration the revenue office holds. Clear '
                    .'billing.tax_oss.waived_since only if the declaration was actually withdrawn',
                    $waivedYear + $bindingYears, $waivedYear, $bindingYears,
                ),
            );
        }
    }

    /** @return list<float> */
    private function warningLevels(): array
    {
        $levels = $this->config->get('billing.tax_oss.warning_levels');

        if (! is_array($levels)) {
            return [];
        }

        $numeric = [];

        foreach ($levels as $level) {
            if (is_numeric($level)) {
                $numeric[] = (float) $level;
            }
        }

        sort($numeric);

        return $numeric;
    }
}
