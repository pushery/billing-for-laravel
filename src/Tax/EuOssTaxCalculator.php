<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Carbon\CarbonImmutable;
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
    /*
     * THE RATES USED TO LIVE HERE, AS A `private const array`, BESIDE A FILE THAT SAID IT WAS THE SOURCE.
     *
     * Both were shipped. The file carries a header and a digest whose stated purpose is the edit nobody
     * sees — a digit changed inside `vendor/`, invisible in every diff, repricing every invoice to a
     * country — and the published documentation told the reader that pricing STOPS when that digest
     * disagrees.
     *
     * Nothing loaded the file. Measured, not reasoned: with the shipped snapshot's `DE` set to 1000 bps and
     * its digest correctly re-pulled, `calculate()` charged 1900 on 100.00. The guard was real, its test was
     * green, and the money path never went near either.
     *
     * Two copies of the same regulated numbers is the deeper half of that. A lockstep test held them equal,
     * which proves today's agreement and nothing about tomorrow's. There is one copy now: the rates come
     * from {@see ShippedTaxRates}, loaded once per boot, and the date they were checked on is the file's own
     * `situation_on` rather than a constant beside them — a date held apart from its numbers is the half
     * that goes quietly wrong.
     */

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
        $code = strtoupper($country);

        return isset($this->shipped->bps[$code]) || in_array($code, self::OUTSIDE_EU_VAT_AREA, true);
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
        return $this->shipped->bps[$country] ?? null;
    }
}
