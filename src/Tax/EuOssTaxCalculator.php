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
    /**
     * The day these rates were last checked against what each member state publishes.
     *
     * It is here because a rate table without a date cannot say that it has gone stale, and going stale is
     * the only thing it reliably does. A member state raises its rate, every sale to that country is priced
     * too low from that day, and the difference is not the buyer's — it is owed by whoever issued the
     * document. Nothing about those invoices looks wrong.
     *
     * Two entries were corrected on this date after being found two points low, which is exactly the failure
     * this constant exists to make visible next time.
     */
    public const string RATES_CHECKED_ON = '2026-07-25';

    private const array RATES = [
        'AT' => 0.20, 'BE' => 0.21, 'BG' => 0.20, 'HR' => 0.25, 'CY' => 0.19, 'CZ' => 0.21,
        'DK' => 0.25, 'EE' => 0.24, 'FI' => 0.255, 'FR' => 0.20, 'DE' => 0.19, 'GR' => 0.24,
        'HU' => 0.27, 'IE' => 0.23, 'IT' => 0.22, 'LV' => 0.21, 'LT' => 0.21, 'LU' => 0.17,
        'MT' => 0.18, 'NL' => 0.21, 'PL' => 0.23, 'PT' => 0.23, 'RO' => 0.21, 'SK' => 0.23,
        'SI' => 0.22, 'ES' => 0.21, 'SE' => 0.25,
    ];

    /**
     * Countries whose supplies are treated as another member state's, by that member state's own law.
     *
     * These are not third countries and they are not members in their own right — they are places a
     * jurisdiction has folded into itself for tax purposes. Read as third countries they produce a zero rate
     * that looks entirely deliberate: the code is a real country, so no guard fires, and the invoice shows a
     * confident 0% on a supply that owed the full rate of the state it belongs to.
     *
     * That is why they are mapped rather than merely removed from the third-country list. Removing them
     * would make them unknown codes and fail loudly, which is better than silence but still wrong: the
     * supply is perfectly taxable, and the system knows exactly whose rate applies.
     *
     * @var array<string, string>
     */
    private const array TREATED_AS = [
        // Transactions to and from Monaco are treated as French for VAT purposes.
        'MC' => 'FR',
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
        'KR', 'KW', 'KY', 'KZ', 'LA', 'LB', 'LC', 'LI', 'LK', 'LR', 'LS', 'LY', 'MA', 'MD',
        'ME', 'MF', 'MG', 'MH', 'MK', 'ML', 'MM', 'MN', 'MO', 'MP', 'MQ', 'MR', 'MS', 'MU', 'MV',
        'MW', 'MX', 'MY', 'MZ', 'NA', 'NC', 'NE', 'NF', 'NG', 'NI', 'NO', 'NP', 'NR', 'NU', 'NZ',
        'OM', 'PA', 'PE', 'PF', 'PG', 'PH', 'PK', 'PM', 'PN', 'PR', 'PS', 'PW', 'PY', 'QA', 'RE',
        'RS', 'RU', 'RW', 'SA', 'SB', 'SC', 'SD', 'SG', 'SH', 'SJ', 'SL', 'SM', 'SN', 'SO', 'SR',
        'SS', 'ST', 'SV', 'SX', 'SY', 'SZ', 'TC', 'TD', 'TF', 'TG', 'TH', 'TJ', 'TK', 'TL', 'TM',
        'TN', 'TO', 'TR', 'TT', 'TV', 'TW', 'TZ', 'UA', 'UG', 'UM', 'US', 'UY', 'UZ', 'VA', 'VC',
        'VE', 'VG', 'VI', 'VN', 'VU', 'WF', 'WS', 'YE', 'YT', 'ZA', 'ZM', 'ZW',
    ];

    /**
     * @param  ?string  $sellerCountry  the seller's ISO country (config billing.company.country), for the cross-border test
     * @param  ?TaxRateMatrix  $matrix  a configured rate table keyed by country AND supply category; absent
     *                                  by default, and absent means the built-in standard-rate table answers
     *                                  exactly as it always did
     */
    public function __construct(
        private ?string $sellerCountry = null,
        private ?TaxRateMatrix $matrix = null,
    ) {}

    /**
     * The rates this package ships, in basis points, for comparison against a source.
     *
     * Exposed for exactly one caller: the conformity probe that asks whether these numbers still match what
     * the publisher says. The table stays private — a reader could otherwise price a supply from it directly
     * and bypass every refusal `calculate()` makes — and this returns a converted COPY, so nothing can reach
     * in and edit the constant that answers on every invoice.
     *
     * @return array<string, int>
     */
    public static function shippedRatesBps(): array
    {
        return array_map(static fn (float $rate): int => (int) round($rate * 10_000), self::RATES);
    }

    /**
     * Whether this calculator can price a supply into a country at all.
     *
     * "Knows" includes a country correctly outside the tax area: zero there is an answer, not an absence.
     * The market gate asks this at boot so an opened market with no rate is refused on a deploy rather than
     * discovered on an invoice, where it looks like a sale that simply carried no tax.
     */
    public function knowsRateFor(string $country): bool
    {
        $code = strtoupper($country);

        return isset(self::RATES[$code]) || in_array($code, self::OUTSIDE_EU_VAT_AREA, true);
    }

    public function calculate(Money $net, TaxContext $context): Money
    {
        // Country codes are matched upper-case: the rate table is keyed by canonical ISO codes, so a
        // lower/mixed-case code ("de") must not miss the table and silently drop to 0% VAT.
        $country = self::resolveTerritory($context->countryCode);
        $seller = $this->sellerCountry !== null ? self::resolveTerritory($this->sellerCountry) : null;

        // The configured matrix answers first where it covers the country, because it is the only source
        // that knows the SUPPLY as well as the destination. Where it does not cover a country the built-in
        // table still does — a partial matrix must not turn a country the calculator has always priced into
        // an unknown one, which is a refusal rather than a smaller table.
        $rateBps = $this->matrix instanceof TaxRateMatrix && $this->matrix->covers($country)
            ? $this->matrix->rateFor($country, $context->rateCategory, $context->hasAudioVisualComponent)
            : $this->standardBpsFor($country);

        // "No rate for this code" has two causes that produce the same number but mean opposite things: a
        // supply outside the EU VAT area is correctly zero-rated, a broken code is a data defect that would
        // under-declare VAT. They are separated BEFORE anything can return a zero — including the reverse
        // charge below, which would otherwise zero-rate an unrecognized country on a validated VAT id.
        if ($rateBps === null && ! in_array($country, self::OUTSIDE_EU_VAT_AREA, true)) {
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
        if ($rateBps === null) {
            return Money::zero($net->currency);
        }

        return $net->proportion($rateBps, 10_000);
    }

    /**
     * The built-in table's rate for a country, in basis points, or null when it has none.
     *
     * The table is written as decimal fractions because that is how rates are published, but every other
     * rate on the money path is an integer in basis points, and the tax is computed with the same integer
     * primitive as every other proportion. Converting here rather than keeping a second numeric path is what
     * stops a rate arriving as 0.19 in one place and 1900 in another.
     */
    /**
     * The country whose rate actually applies to a code, resolving a territorial alias.
     *
     * Done once, here, rather than at each caller: the alias is a fact about the code and not about who is
     * asking, and a caller that forgot to resolve it would silently price a taxable supply at zero.
     */
    public static function resolveTerritory(?string $country): string
    {
        $code = strtoupper(trim((string) $country));

        return self::TREATED_AS[$code] ?? $code;
    }

    private function standardBpsFor(string $country): ?int
    {
        $rate = self::RATES[$country] ?? null;

        return $rate === null ? null : (int) round($rate * 10_000);
    }
}
