<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Carbon\CarbonImmutable;
use Pushery\Billing\Contracts\TaxCalculator;
use Pushery\Billing\Enums\RateCoverage;
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
    /*
     * THE RATES LIVE IN ONE PLACE: the shipped snapshot, loaded through {@see ShippedTaxRates} once per boot.
     *
     * The file carries a header and a digest whose stated purpose is the edit nobody sees — a digit changed
     * inside `vendor/`, invisible in every diff, repricing every invoice to a country — and the published
     * documentation tells the reader that pricing STOPS when that digest disagrees. A copy of the rates held
     * here as a `private const array` would be a money path that never goes near that guard: the guard real,
     * its test green, and every price taken from the copy.
     *
     * Two copies of the same regulated numbers would be the deeper half of that. A lockstep test holding them
     * equal proves today's agreement and nothing about tomorrow's. The date the rates were checked on is the
     * file's own `situation_on` rather than a constant beside them — a date held apart from its numbers is the
     * half that goes quietly wrong.
     */

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
        /**
         * The shipped, digest-checked rate table this calculator prices from.
         *
         * Nullable for the convenience of a caller with no container — and null does NOT mean "fall back to a
         * second copy of the numbers", because there is no second copy any more. It means "load the same file
         * the singleton would have handed you", so every path ends at one table behind one digest.
         */
        ?ShippedTaxRates $shipped = null,
        /**
         * The operator's rate HISTORY as dated intervals, where they configured one.
         *
         * Null on almost every installation, and inert when it is: the tax point on the context is then
         * carried and ignored, so a caller that started supplying one sees the answer it always got.
         */
        private ?DatedTaxRateTable $history = null,
        /**
         * The operator's classification of the countries this installation sells into, where they bound one.
         *
         * Null means none is bound, and then nothing changes: a country with a rate is priced at it and one
         * outside the EU VAT area at nothing. Bound, it is asked before anything is priced. A country in neither
         * of its lists refuses, one it records as deliberately untaxed prices at nothing, and one it covers is
         * priced at its rate, refusing where no table carries one.
         */
        private ?CoverageMap $coverage = null,
    ) {
        $this->shipped = $shipped ?? ShippedTaxRates::shipped();
    }

    /**
     * The rate table in force.
     *
     * Resolved once, in the constructor. In a booted application the factory hands in the container
     * singleton, so the file is read at boot and never again — verifying a digest means hashing the table,
     * and an invoice must not pay for that.
     */
    private ShippedTaxRates $shipped;

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
        return ShippedTaxRates::shipped()->bps;
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
        // Resolved as calculate() resolves it, so a place a member folds into itself has that member's rate here
        // too. Unresolved, an open market Monaco refused the boot over a rate the calculator charges.
        $code = self::resolveTerritory($country);

        return isset($this->shipped->bps[$code]) || in_array($code, self::OUTSIDE_EU_VAT_AREA, true);
    }

    public function calculate(Money $net, TaxContext $context): Money
    {
        // Country codes are matched upper-case: the rate table is keyed by canonical ISO codes, so a
        // lower/mixed-case code ("de") must not miss the table and silently drop to 0% VAT.
        $country = self::resolveTerritory($context->countryCode);
        $seller = $this->sellerCountry !== null ? self::resolveTerritory($this->sellerCountry) : null;

        // Asked before pricing, so a refusal comes while there is nothing to un-issue.
        $coverage = $this->coverage?->assertKnown($country);

        if ($coverage === RateCoverage::DeliberatelyUntaxed) {
            return Money::zero($net->currency);
        }

        // The configured matrix answers first where it covers the country, because it is the only source
        // that knows the SUPPLY as well as the destination. Where it does not cover a country the built-in
        // table still does — a partial matrix must not turn a country the calculator has always priced into
        // an unknown one, which is a refusal rather than a smaller table.
        // The DATED table answers first, and only when both halves are present: the caller knows when the
        // supply was taxed, and the operator has taken responsibility for that country by putting it in the
        // history. The law binds the rate to the tax point rather than to the moment of lookup (Art. 93 VAT
        // Directive), so where both are known there is no other defensible answer.
        //
        // A country the history does NOT carry falls through, for the same reason a partial matrix falls
        // through: opting into intervals for one member state says nothing about the others, and turning
        // them into refusals would be a smaller table read as an unknown one. Within a country it DOES
        // carry, a tax point in a gap is a refusal — `intervalAt()` throws rather than reaching for the
        // nearest rate, because an invention with a date on it cannot be told apart from a fact.
        $rateBps = $this->history instanceof DatedTaxRateTable
            && $context->taxPoint instanceof CarbonImmutable
            && $this->history->knowsCountry($country)
                ? $this->history->rateAt($country, $context->rateCategory->withAudioVisual($context->hasAudioVisualComponent), $context->taxPoint)
                : ($this->matrix instanceof TaxRateMatrix && $this->matrix->covers($country)
                    ? $this->matrix->rateFor($country, $context->rateCategory, $context->hasAudioVisualComponent)
                    : $this->standardBpsFor($country));

        // "No rate for this code" has two causes that produce the same number but mean opposite things: a
        // supply outside the EU VAT area is correctly zero-rated, a broken code is a data defect that would
        // under-declare VAT. They are separated BEFORE anything can return a zero — including the reverse
        // charge below, which would otherwise zero-rate an unrecognized country on a validated VAT id.
        if ($rateBps === null && $coverage === RateCoverage::Covered) {
            throw UnknownTaxCountry::coveredWithoutRate($country);
        }

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
     * The country whose rate actually applies to a code, resolving a territorial alias.
     *
     * The aliases live in {@see UnionMembership::territoryOf()}, beside the membership, so that the union test
     * and the place of supply resolve a code exactly as the rate does. A caller that forgot to resolve it
     * would silently price a taxable supply at zero.
     */
    public static function resolveTerritory(?string $country): string
    {
        return UnionMembership::territoryOf($country);
    }

    /**
     * The built-in table's rate for a country, in basis points, or null when it has none.
     *
     * The table is written as decimal fractions because that is how rates are published, but every other
     * rate on the money path is an integer in basis points, and the tax is computed with the same integer
     * primitive as every other proportion. Converting here rather than keeping a second numeric path is what
     * stops a rate arriving as 0.19 in one place and 1900 in another.
     */
    private function standardBpsFor(string $country): ?int
    {
        return $this->shipped->bps[$country] ?? null;
    }
}
