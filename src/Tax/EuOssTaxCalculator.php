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
final readonly class EuOssTaxCalculator implements TaxCalculator
{
    /** @var array<string,float> ISO-3166 country → standard VAT rate */
    private const array RATES = [
        'AT' => 0.20, 'BE' => 0.21, 'BG' => 0.20, 'HR' => 0.25, 'CY' => 0.19, 'CZ' => 0.21,
        'DK' => 0.25, 'EE' => 0.22, 'FI' => 0.255, 'FR' => 0.20, 'DE' => 0.19, 'GR' => 0.24,
        'HU' => 0.27, 'IE' => 0.23, 'IT' => 0.22, 'LV' => 0.21, 'LT' => 0.21, 'LU' => 0.17,
        'MT' => 0.18, 'NL' => 0.21, 'PL' => 0.23, 'PT' => 0.23, 'RO' => 0.19, 'SK' => 0.23,
        'SI' => 0.22, 'ES' => 0.21, 'SE' => 0.25,
    ];

    /** @param ?string $sellerCountry the seller's ISO country (config billing.company.country), for the cross-border test */
    public function __construct(private ?string $sellerCountry = null) {}

    public function calculate(Money $net, TaxContext $context): Money
    {
        // Country codes are matched upper-case: the rate table is keyed by canonical ISO codes, so a
        // lower/mixed-case code ("de") must not miss the table and silently drop to 0% VAT.
        $country = strtoupper($context->countryCode);
        $seller = $this->sellerCountry !== null ? strtoupper($this->sellerCountry) : null;

        // The reverse charge is a CROSS-BORDER intra-EU mechanism (Art. 196): a validated business in a
        // DIFFERENT EU country than the seller self-accounts for the VAT (0%). A DOMESTIC (same-country) B2B
        // supply owes the normal domestic VAT — zero-rating it would silently under-charge every home-country
        // business. When the seller country is unknown, we cannot prove it is cross-border, so we do not
        // zero-rate (never under-charge).
        if ($context->isReverseChargeCandidate() && $seller !== null && $country !== $seller) {
            return Money::zero($net->currency);
        }

        $rate = self::RATES[$country] ?? 0.0;

        return Money::of((int) round($net->minorUnits * $rate), $net->currency);
    }
}
