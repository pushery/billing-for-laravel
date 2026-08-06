<?php

declare(strict_types=1);

namespace Pushery\Billing\Preflight\Profiles;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Carbon;
use Override;
use Pushery\Billing\Contracts\JurisdictionProfile;
use Pushery\Billing\Contracts\RequiresElectronicInvoicing;
use Pushery\Billing\Contracts\RequiresTaxStatusHold;
use Pushery\Billing\Contracts\SuppliesArchetypeRegimes;
use Pushery\Billing\Contracts\SuppliesDistanceSaleThreshold;
use Pushery\Billing\Contracts\SuppliesExchangeRateBasis;
use Pushery\Billing\Contracts\SuppliesMarginSchemeWording;
use Pushery\Billing\Contracts\SuppliesReportingExchangeRateBasis;
use Pushery\Billing\Contracts\SuppliesTaxRates;
use Pushery\Billing\Enums\ExchangeRateBasis;
use Pushery\Billing\Enums\GoLiveStep;
use Pushery\Billing\Enums\SupplyRegime;
use Pushery\Billing\Enums\TaxArchetype;
use Pushery\Billing\Preflight\Checkpoints\AttestedCheckpoint;

/**
 * The German jurisdiction profile: what a platform established in Germany owes before its first routed sale.
 *
 * Every point here is an ATTESTATION, and that is a statement about the points, not a shortcut. Publishing
 * terms and filing a registration happen outside the application entirely; no configuration value and no
 * database row is evidence of either. The honest model is therefore the operator's dated, versioned word,
 * which the package holds to a version and re-opens when the requirement moves.
 *
 * The version constants are the expiry mechanism. When a later release changes what the terms must contain
 * or what the registration must cover, the constant moves with it, every recorded attestation stops
 * matching, and the point goes red until somebody has actually re-read it. Bumping one is therefore a
 * deliberate act with a real cost to every operator — which is the correct cost for "the obligation
 * changed".
 */
final readonly class GermanJurisdictionProfile implements JurisdictionProfile, RequiresElectronicInvoicing, RequiresTaxStatusHold, SuppliesArchetypeRegimes, SuppliesDistanceSaleThreshold, SuppliesExchangeRateBasis, SuppliesMarginSchemeWording, SuppliesReportingExchangeRateBasis, SuppliesTaxRates
{
    /**
     * The revision of the marketplace terms package an operator must have published. It moves whenever the
     * package starts to rely on a clause that was not required before.
     */
    public const string TERMS_VERSION = '2026-07';

    /** The revision of the tax-authority registration requirements below. */
    public const string REGISTRATIONS_VERSION = '2026-07';

    /**
     * The day the rates below were read from the published tables.
     *
     * It is a constant rather than a computed value because it says when somebody LOOKED, and a value that
     * moved on its own would say nothing. `billing:doctor` reports the resulting age so an operator learns
     * the table has drifted from a diagnostic rather than from a tax return.
     */
    public const string RATES_VALID_FROM = '2026-07-01';

    public function __construct(private Repository $config) {}

    public function key(): string
    {
        return 'de';
    }

    /**
     * The domestic bands, and only those.
     *
     * Two bands, because that is what the country grants for the kinds of supply this package prices: the
     * standard rate and the reduced one that applies to a purely textual work. The reduced band is the whole
     * reason a rate table needs a second dimension at all — a country-only table charges the standard rate
     * on such a supply, and since the buyer's price does not move, the entire difference comes off the
     * seller's payout with nothing looking wrong.
     *
     * Deliberately NOT the other member states' rates. A cross-border scheme owes the destination's rate,
     * and this profile would be asserting twenty-six tables it has no way to keep current — a stale rate
     * stated confidently is worse than an absent one, which at least falls back to the bundled table. An
     * operator filing across borders configures those rates, and the age check tells them when they have
     * aged.
     */
    public function taxRates(): array
    {
        return ['DE' => ['standard' => 1_900, 'reduced' => 700]];
    }

    public function taxRatesValidFrom(): CarbonInterface
    {
        return Carbon::parse(self::RATES_VALID_FROM);
    }

    /**
     * One union-wide limit across all cross-border consumer sales, not one per country.
     *
     * Per country it would be a different rule wearing the same number: a seller spreading turnover across
     * five countries would stay under it forever while owing tax in all five.
     */
    public function distanceSaleThresholdMinor(): int
    {
        return 1_000_000;
    }

    /**
     * Every business document electronically, from the first one.
     *
     * Issuing early makes the transition questions moot rather than answering them one at a time — including
     * which side's turnover decides a deadline for a self-billed document, a question with no comfortable
     * answer and no need for one once every document is already electronic.
     */
    /**
     * The prescribed wording for a margin-taxed document.
     *
     * A translation key rather than a literal, and that is the right call here even though the wording is
     * prescribed: each language has its own prescribed form, and the document is read in the language it was
     * issued in. What must never happen is paraphrasing — a document carrying an approximation of the words
     * is not carrying them — which is why the strings are fixed per locale rather than composed.
     */
    public function marginSchemeNote(): string
    {
        return 'billing::invoice.margin_scheme_note';
    }

    public function requiresElectronicInvoicing(): bool
    {
        return true;
    }

    public function distanceSaleThresholdValidFrom(): CarbonInterface
    {
        return Carbon::parse(self::RATES_VALID_FROM);
    }

    public function checkpoints(): array
    {
        return [
            new AttestedCheckpoint(
                config: $this->config,
                key: 'terms.marketplace_package',
                step: GoLiveStep::Terms,
                blocking: true,
                requiredVersion: self::TERMS_VERSION,
                subject: 'The marketplace terms are published — commission agency (§ 3 Abs. 11 UStG), the '.
                    'self-billing agreement and the consequences of an objection, the merchant\'s duty to '.
                    'report status changes and re-attest, the clawback and set-off clause, the buyer-protection '.
                    'terms, and the withdrawal notices together with the flow that extinguishes the right',
            ),
            new AttestedCheckpoint(
                config: $this->config,
                key: 'registrations.oss',
                step: GoLiveStep::Registrations,
                blocking: true,
                requiredVersion: self::REGISTRATIONS_VERSION,
                subject: 'The One-Stop-Shop registration is filed and the § 3c Abs. 4 UStG threshold waiver '.
                    'declared, both before the first cross-border sale',
            ),
            new AttestedCheckpoint(
                config: $this->config,
                key: 'registrations.platform_reporting_inquiry',
                step: GoLiveStep::Registrations,
                blocking: false,
                requiredVersion: self::REGISTRATIONS_VERSION,
                subject: 'The § 10 PStTG inquiry covering the product portfolio is filed. This runs in '.
                    'parallel and does not block the first sale, which is why it is reported as a warning '.
                    'rather than as a failure',
            ),
        ];
    }

    /**
     * § 16 Abs. 6 S. 1 UStG: the ministry's published monthly average, and it is mandatory rather than
     * preferred — using a daily rate for domestic turnover needs the tax office's permission.
     *
     * Deliberately NOT the central bank's rate, which is what somebody reaching for the obvious source
     * would take: the one-stop-shop rule uses exactly that and expressly excludes the monthly average, on
     * the same turnover. Two rules, one sale, and the difference is which document you are issuing.
     */
    #[Override]
    public function documentExchangeRateBasis(): ExchangeRateBasis
    {
        return ExchangeRateBasis::CentralBankMonthlyAverage;
    }

    /**
     * The one-stop-shop rule, and it is a different rule from the one above on the same turnover.
     *
     * § 16 (6) sentence 4 UStG, transposing Art. 369h(2) of the VAT Directive: the central bank's rate on
     * the last day of the tax period, and where that day has none, the next day one was published. Sentence
     * 4 displaces sentences 1 to 3 — so the ministry's monthly average, which is the correct rule for the
     * document, is expressly NOT available here. There is no election to make: the two figures diverge by
     * law, and a filer who used one rule for both would be wrong on one of them.
     */
    #[Override]
    public function reportingExchangeRateBasis(): ExchangeRateBasis
    {
        return ExchangeRateBasis::CentralBankAtPeriodEnd;
    }

    /**
     * Goods sold between private people are arranged, never resold.
     *
     * The platform cannot be reselling something it never owned, and neither party is in business — so the
     * chain a commission regime describes, where the platform buys and sells on, has no first link. That
     * leaves arranging the sale as the only description the facts support, whatever the platform configured
     * for everything else.
     *
     * This lived in the neutral resolver until 2026-07-28. The FACT it rests on is jurisdiction-neutral;
     * concluding a REGIME from it is not, which is why it is stated here by a profile that answers for one
     * jurisdiction rather than there by a class that answers for all of them.
     *
     * Every other archetype is left to the platform's configured default: nothing in the law compels a
     * regime for a download or a service, so choosing one here would be inventing an obligation.
     */
    #[Override]
    public function regimeForArchetype(TaxArchetype $archetype): ?SupplyRegime
    {
        return $archetype === TaxArchetype::ConsumerGoods ? SupplyRegime::Intermediation : null;
    }
}
