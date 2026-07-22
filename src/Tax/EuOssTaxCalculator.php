<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Pushery\Billing\Contracts\TaxCalculator;
use Pushery\Billing\Exceptions\UnknownTaxCountry;
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

    /**
     * The assigned ISO 3166-1 alpha-2 codes that are NOT in the rate table above — i.e. every country
     * outside the EU VAT area. Membership here is what makes a zero rate a DELIBERATE answer ("this supply
     * is outside the EU VAT area") instead of a fallthrough that also swallows broken codes.
     *
     * This is an identity list, not a market list: it says a code denotes a real country, nothing about
     * whether we sell there. The configurable market allowlist is a separate concern.
     *
     * @var list<string>
     */
    private const array OUTSIDE_EU_VAT_AREA = [
        'AD', 'AE', 'AF', 'AG', 'AI', 'AL', 'AM', 'AO', 'AQ', 'AR', 'AS', 'AU', 'AW', 'AX', 'AZ',
        'BA', 'BB', 'BD', 'BF', 'BH', 'BI', 'BJ', 'BL', 'BM', 'BN', 'BO', 'BQ', 'BR', 'BS', 'BT',
        'BV', 'BW', 'BY', 'BZ', 'CA', 'CC', 'CD', 'CF', 'CG', 'CH', 'CI', 'CK', 'CL', 'CM', 'CN',
        'CO', 'CR', 'CU', 'CV', 'CW', 'CX', 'DJ', 'DM', 'DO', 'DZ', 'EC', 'EG', 'EH', 'ER', 'ET',
        'FJ', 'FK', 'FM', 'FO', 'GA', 'GB', 'GD', 'GE', 'GF', 'GG', 'GH', 'GI', 'GL', 'GM', 'GN',
        'GP', 'GQ', 'GS', 'GT', 'GU', 'GW', 'GY', 'HK', 'HM', 'HN', 'HT', 'ID', 'IL', 'IM', 'IN',
        'IO', 'IQ', 'IR', 'IS', 'JE', 'JM', 'JO', 'JP', 'KE', 'KG', 'KH', 'KI', 'KM', 'KN', 'KP',
        'KR', 'KW', 'KY', 'KZ', 'LA', 'LB', 'LC', 'LI', 'LK', 'LR', 'LS', 'LY', 'MA', 'MC', 'MD',
        'ME', 'MF', 'MG', 'MH', 'MK', 'ML', 'MM', 'MN', 'MO', 'MP', 'MQ', 'MR', 'MS', 'MU', 'MV',
        'MW', 'MX', 'MY', 'MZ', 'NA', 'NC', 'NE', 'NF', 'NG', 'NI', 'NO', 'NP', 'NR', 'NU', 'NZ',
        'OM', 'PA', 'PE', 'PF', 'PG', 'PH', 'PK', 'PM', 'PN', 'PR', 'PS', 'PW', 'PY', 'QA', 'RE',
        'RS', 'RU', 'RW', 'SA', 'SB', 'SC', 'SD', 'SG', 'SH', 'SJ', 'SL', 'SM', 'SN', 'SO', 'SR',
        'SS', 'ST', 'SV', 'SX', 'SY', 'SZ', 'TC', 'TD', 'TF', 'TG', 'TH', 'TJ', 'TK', 'TL', 'TM',
        'TN', 'TO', 'TR', 'TT', 'TV', 'TW', 'TZ', 'UA', 'UG', 'UM', 'US', 'UY', 'UZ', 'VA', 'VC',
        'VE', 'VG', 'VI', 'VN', 'VU', 'WF', 'WS', 'YE', 'YT', 'ZA', 'ZM', 'ZW',
    ];

    /** @param ?string $sellerCountry the seller's ISO country (config billing.company.country), for the cross-border test */
    public function __construct(private ?string $sellerCountry = null) {}

    public function calculate(Money $net, TaxContext $context): Money
    {
        // Country codes are matched upper-case: the rate table is keyed by canonical ISO codes, so a
        // lower/mixed-case code ("de") must not miss the table and silently drop to 0% VAT.
        $country = strtoupper($context->countryCode);
        $seller = $this->sellerCountry !== null ? strtoupper($this->sellerCountry) : null;

        $rate = self::RATES[$country] ?? null;

        // "No rate for this code" has two causes that produce the same number but mean opposite things: a
        // supply outside the EU VAT area is correctly zero-rated, a broken code is a data defect that would
        // under-declare VAT. They are separated BEFORE anything can return a zero — including the reverse
        // charge below, which would otherwise zero-rate an unrecognized country on a validated VAT id.
        if ($rate === null && ! in_array($country, self::OUTSIDE_EU_VAT_AREA, true)) {
            throw UnknownTaxCountry::code($context->countryCode);
        }

        // The reverse charge is a CROSS-BORDER intra-EU mechanism (Art. 196): a validated business in a
        // DIFFERENT EU country than the seller self-accounts for the VAT (0%). A DOMESTIC (same-country) B2B
        // supply owes the normal domestic VAT — zero-rating it would silently under-charge every home-country
        // business. When the seller country is unknown, we cannot prove it is cross-border, so we do not
        // zero-rate (never under-charge).
        if ($context->isReverseChargeCandidate() && $seller !== null && $country !== $seller) {
            return Money::zero($net->currency);
        }

        // Reached only for a code proven above to be an assigned country: outside the EU VAT area, so no EU
        // VAT is due. This is the named third-country outcome, never a landing spot for an unknown code.
        if ($rate === null) {
            return Money::zero($net->currency);
        }

        return Money::of((int) round($net->minorUnits * $rate), $net->currency);
    }
}
