<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Pushery\Billing\Contracts\TaxCalculator;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\TaxContext;

/**
 * A static EU-OSS VAT table implementation of TaxCalculator — the local fallback when the provider
 * does not compute tax. It charges the destination country's standard rate to a consumer, zero to a
 * verified intra-EU business (reverse charge), and zero for a country outside the table.
 *
 * The rates are the EU-27 standard rates; keep them current (this is a simplified table, not a
 * substitute for a full tax engine).
 *
 * @internal rates are standard VAT and may lag; verify before relying on them for filing.
 */
final class EuOssTaxCalculator implements TaxCalculator
{
    /** @var array<string,float> ISO-3166 country → standard VAT rate */
    private const array RATES = [
        'AT' => 0.20, 'BE' => 0.21, 'BG' => 0.20, 'HR' => 0.25, 'CY' => 0.19, 'CZ' => 0.21,
        'DK' => 0.25, 'EE' => 0.22, 'FI' => 0.255, 'FR' => 0.20, 'DE' => 0.19, 'GR' => 0.24,
        'HU' => 0.27, 'IE' => 0.23, 'IT' => 0.22, 'LV' => 0.21, 'LT' => 0.21, 'LU' => 0.17,
        'MT' => 0.18, 'NL' => 0.21, 'PL' => 0.23, 'PT' => 0.23, 'RO' => 0.19, 'SK' => 0.23,
        'SI' => 0.22, 'ES' => 0.21, 'SE' => 0.25,
    ];

    public function calculate(Money $net, TaxContext $context): Money
    {
        if ($context->isReverseChargeCandidate()) {
            return Money::zero($net->currency);
        }

        $rate = self::RATES[$context->countryCode] ?? 0.0;

        return Money::of((int) round($net->minorUnits * $rate), $net->currency);
    }
}
