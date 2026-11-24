<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Carbon\CarbonImmutable;

/**
 * An invoiced period that a change of rate leaves taxed at another rate than the one of its last day, as
 * {@see TaxRateCorrections} names it: the invoice and its owner, the period and its country, the net, the tax it charged
 * at its rate, and the rate of the last day with the tax at that rate. Amounts are in minor units of the invoice's
 * currency, rates in basis points.
 */
final readonly class TaxRateCorrection
{
    public function __construct(
        public int $invoiceId,
        public ?string $number,
        public ?string $ownerType,
        public ?int $ownerId,
        public ?CarbonImmutable $periodStart,
        public CarbonImmutable $periodEnd,
        public string $country,
        public string $currency,
        public int $netMinor,
        public int $taxChargedMinor,
        public int $chargedRateBps,
        public int $rateOfTheLastDayBps,
        public int $taxAtThatRateMinor,
    ) {}
}
