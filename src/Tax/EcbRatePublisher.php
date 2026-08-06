<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Pushery\Billing\Contracts\PublishesExchangeRates;

/**
 * The European Central Bank's daily reference rates — the publisher this package ships bound.
 *
 * Shipped and bound so that an installation which never thinks about publishers keeps precisely the
 * behavior it had: same series, same source name, same URL. What changes is that the identity is now
 * REPLACEABLE, which is what an installation filing under a different jurisdiction's rule needs.
 *
 * The series is the SDMX daily reference rate: one quote currency, denominated in euro, published on
 * business days. Rates are stored in the direction the bank publishes them and never turned around —
 * the inverse is a figure the publisher never issued, and {@see FrozenExchangeRate} refuses to invent it.
 */
final readonly class EcbRatePublisher implements PublishesExchangeRates
{
    /** The SDMX daily reference-rate series: daily, one quote currency, denominated in euro. */
    private const string SERIES = 'https://data-api.ecb.europa.eu/service/data/EXR/D.%s.EUR.SP00.A';

    public function seriesUrl(string $currency, string $from, string $to): string
    {
        return sprintf(self::SERIES, strtoupper($currency))
            .'?'.http_build_query([
                'startPeriod' => $from,
                'endPeriod' => $to,
                'format' => 'csvdata',
            ]);
    }

    public function sourceName(): string
    {
        return 'ECB';
    }

    public function describe(): string
    {
        return 'European Central Bank';
    }
}
