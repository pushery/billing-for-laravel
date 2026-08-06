<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Enums\CreatorTaxStatus;
use Pushery\Billing\Enums\CreatorTaxStatusSource;
use Pushery\Billing\Exceptions\InvalidBillingConfig;
use Pushery\Billing\Models\CreatorTaxStatusRecord;

/**
 * A merchant's own dated statement of how their supply is taxed — and when that statement runs out.
 *
 * It has to run out. The commonest threshold in this area is a statement about a year that has not ended
 * yet, so a declaration made in March says nothing about the following January; and the platform cannot
 * answer the question on the merchant's behalf, because it only ever sees what was sold HERE. An expiry is
 * therefore not hygiene, it is the only way the question gets asked again at all.
 *
 * The founding year is collected with it and validated rather than derived. When the business started and
 * when it signed up here are different facts that differ routinely, and a derivation would be wrong
 * invisibly: nothing about a threshold computed from the wrong starting year looks wrong.
 */
final readonly class CreatorSelfDeclaration
{
    /** No business predates modern bookkeeping in a way this package needs to model. */
    private const int EARLIEST_PLAUSIBLE_YEAR = 1800;

    public function __construct(
        private Repository $config,
        private CreatorTaxStatusLedger $ledger,
    ) {}

    /**
     * Record what a merchant declared.
     *
     * @param  string  $evidenceRef  what was accepted and when — the wording, its version, the moment.
     *                               Without it a declaration is an assertion nobody can go back to.
     */
    public function declare(
        Model $merchant,
        CreatorTaxStatus $status,
        int $businessFoundedYear,
        string $evidenceRef,
        ?CarbonImmutable $effectiveFrom = null,
        ?CarbonImmutable $now = null,
    ): CreatorTaxStatusRecord {
        $at = $now ?? CarbonImmutable::now();

        $this->assertPlausibleFoundingYear($businessFoundedYear, $at);

        return $this->ledger->record(
            merchant: $merchant,
            status: $status,
            effectiveFrom: $effectiveFrom ?? $at,
            source: CreatorTaxStatusSource::SelfDeclaration,
            evidenceRef: $evidenceRef,
            // Every declaration expires at the next year boundary. A statement about a year in progress
            // cannot outlive that year, and nothing here may quietly extend it.
            attestedUntil: $this->expiryFor($at),
            businessFoundedYear: $businessFoundedYear,
        );
    }

    /**
     * When a declaration made now stops answering.
     *
     * The grace period is added to the year boundary rather than replacing it: the obligation arrives on
     * the first day of the year, and the grace is how long somebody has to respond to it — not a license to
     * treat last year's answer as this year's.
     */
    public function expiryFor(CarbonImmutable $now): CarbonImmutable
    {
        return $now->addYear()->startOfYear()->addDays($this->graceDays());
    }

    private function assertPlausibleFoundingYear(int $year, CarbonImmutable $at): void
    {
        // A future year is not a slip to round off: the threshold that reads it treats an early year and a
        // late one as different regimes, so a wrong one changes the answer rather than blurring it.
        if ($year > $at->year || $year < self::EARLIEST_PLAUSIBLE_YEAR) {
            throw InvalidBillingConfig::implausibleFoundingYear($year, self::EARLIEST_PLAUSIBLE_YEAR, $at->year);
        }
    }

    private function graceDays(): int
    {
        $days = $this->config->get('billing.tax_small_business.reattestation.grace_days', 30);

        return is_int($days) && $days >= 0 ? $days : 30;
    }
}
