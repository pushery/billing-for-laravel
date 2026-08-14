<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Carbon;
use Pushery\Billing\Contracts\SuppliesTaxRates;
use Pushery\Billing\Contracts\TaxCalculator;
use Pushery\Billing\Enums\TaxRateCategory;
use Pushery\Billing\Exceptions\InvalidBillingConfig;
use Pushery\Billing\Preflight\CheckpointRegistry;

/**
 * Selects the tax calculator from config('billing.tax'): the static EU-OSS VAT table, the
 * provider-delegate (Stripe Tax), or the no-op.
 *
 * An unresolvable mode still falls back to the no-op here, but that fallback is a last resort and NOT a
 * safety property: charging no tax is the dangerous direction for a seller, not the safe one, because it
 * under-declares silently. TaxSupportGuard refuses an unresolvable mode at boot so this fallback is never
 * reached in a booted application — see MODES, which is the authority both sides read.
 */
final readonly class TaxCalculatorFactory
{
    /**
     * Every tax mode make() can actually resolve to a calculator. This is the single authority for what a
     * valid billing.tax is: TaxSupportGuard refuses anything outside it at boot, and a lockstep test proves
     * each entry here really has a match arm below (so the two can never drift apart).
     *
     * @var list<string>
     */
    public const array MODES = ['none', 'eu_oss', 'provider', 'stripe'];

    /**
     * The subset of MODES that hands tax computation to the payment provider, and therefore REQUIRES a
     * driver that computes provider tax. 'stripe' is an alias of 'provider' here — it resolves to the same
     * calculator below, so any consumer of this classification must treat the two alike.
     *
     * @var list<string>
     */
    public const array PROVIDER_MODES = ['provider', 'stripe'];

    /**
     * @param  ?CheckpointRegistry  $profiles  resolves the active jurisdiction profile, which may carry its
     *                                         country's rates; optional so a caller that only needs a
     *                                         calculator still constructs this with a config repository alone
     */
    public function __construct(
        private Repository $config,
        private ?CheckpointRegistry $profiles = null,
        /**
         * The shipped rate table, resolved ONCE for the application rather than per invoice.
         *
         * Injected rather than looked up so the "read the file once per boot" property is structural: the
         * provider binds a singleton, this passes it through, and nothing on the money path can quietly
         * acquire its own copy.
         */
        private ?ShippedTaxRates $shipped = null,
    ) {}

    public function make(): TaxCalculator
    {
        return match ($this->config->get('billing.tax', 'none')) {
            // The seller's own country drives the domestic-vs-cross-border reverse-charge decision.
            'eu_oss' => new EuOssTaxCalculator($this->sellerCountry(), $this->rateMatrix(), $this->shipped, $this->rateHistory()),
            'provider', 'stripe' => new StripeTaxCalculator,
            default => new NoTaxCalculator,
        };
    }

    private function sellerCountry(): ?string
    {
        $country = $this->config->get('billing.company.country');

        return is_string($country) && $country !== '' ? $country : null;
    }

    /**
     * The rate table that actually answers for this installation, or null when the shipped calculator does.
     *
     * Public because something outside has to be able to ASK. `billing:doctor` reports how old the rates
     * are, and without this seam it had to re-derive which table answers — the same precedence written a
     * second time, in a second file, where a third source added here would never reach it. The symptom of
     * that drift is the one the doctor exists to prevent: an operator told the age of a table that is not
     * the one being calculated with.
     *
     * Null is a real answer rather than a failure: it means no configured table and no profile supplied
     * one, so the shipped calculator answers and its date is a constant rather than a loaded value.
     */
    public function answeringRateMatrix(): ?TaxRateMatrix
    {
        return $this->rateMatrix();
    }

    /**
     * The configured country-and-category rate table, or null when none is configured.
     *
     * A malformed table is REFUSED rather than ignored. Ignoring it would leave the standard rate charged on
     * every reduced-rate supply — the same silent over- or under-charge the configuration exists to prevent,
     * and with no symptom, since a wrong rate looks exactly like a right one on an invoice. The absent case
     * is the only quiet one, because absent means "this jurisdiction has one band" rather than "something
     * went wrong here".
     */
    private function rateMatrix(): ?TaxRateMatrix
    {
        $matrix = $this->config->get('billing.tax_matrix');

        // Configuration wins where it is present. An operator who has priced their own table has a reason
        // the package cannot know — a rate the shipped profile has not caught up with, most obviously — and
        // a profile silently overriding that would be the package deciding it knows better about a number
        // whose correctness only the operator can be accountable for.
        if ($matrix === null) {
            return $this->profileRates();
        }

        if (! is_array($matrix) || ! is_array($matrix['rates'] ?? null) || ! is_string($matrix['valid_from'] ?? null)) {
            throw InvalidBillingConfig::forKey(
                'billing.tax_matrix',
                'an array with a "valid_from" date string and a "rates" array of country => '
                .'category => basis points, or null when the jurisdiction has a single rate band'
            );
        }

        /** @var array<string, array<string, int>> $rates */
        $rates = $matrix['rates'];

        return new TaxRateMatrix($rates, Carbon::parse($matrix['valid_from']));
    }

    /**
     * The configured rate HISTORY as dated intervals, or null when the installation has not configured one.
     *
     * Null is the ordinary case and means exactly one thing: this installation prices at one table, and a
     * tax point arriving on the money path changes nothing for it. That inertness is deliberate — the tax
     * point now travels on every call whether or not anybody opted in, so reading its mere presence as
     * "consult the intervals" would refuse every sale on every install that never configured any.
     *
     * A MALFORMED history is refused rather than skipped, for the reason the surrounding table already
     * gives: a skipped history leaves every historic document at today's rate, with no symptom, which is
     * the precise defect it was configured to close. An overlapping pair is refused by the table itself —
     * one supply cannot have two rates, and picking the first match would decide that silently.
     */
    private function rateHistory(): ?DatedTaxRateTable
    {
        $matrix = $this->config->get('billing.tax_matrix');

        if (! is_array($matrix) || ! array_key_exists('history', $matrix)) {
            return null;
        }

        $history = $matrix['history'];

        if ($history === null || $history === []) {
            return null;
        }

        if (! is_array($history)) {
            throw InvalidBillingConfig::forKey('billing.tax_matrix.history', $this->historyShape());
        }

        $table = new DatedTaxRateTable;

        foreach ($history as $entry) {
            if (! is_array($entry)
                || ! is_string($entry['valid_from'] ?? null)
                || ! is_string($entry['source'] ?? null)
                || ! is_string($entry['source_version'] ?? null)
                || ! is_string($entry['fetched_at'] ?? null)
                || ! is_array($entry['rates'] ?? null)) {
                throw InvalidBillingConfig::forKey('billing.tax_matrix.history', $this->historyShape());
            }

            $validTo = $entry['valid_to'] ?? null;

            if ($validTo !== null && ! is_string($validTo)) {
                throw InvalidBillingConfig::forKey('billing.tax_matrix.history', $this->historyShape());
            }

            $approvedBy = $entry['approved_by'] ?? null;

            foreach ($entry['rates'] as $country => $bands) {
                if (! is_string($country) || ! is_array($bands)) {
                    throw InvalidBillingConfig::forKey('billing.tax_matrix.history', $this->historyShape());
                }

                foreach ($bands as $band => $bps) {
                    $category = TaxRateCategory::tryFrom((string) $band);

                    if (! $category instanceof TaxRateCategory || ! is_int($bps)) {
                        throw InvalidBillingConfig::forKey('billing.tax_matrix.history', $this->historyShape());
                    }

                    $table->add(new TaxRateInterval(
                        country: strtoupper($country),
                        category: $category,
                        rateBps: $bps,
                        validFrom: CarbonImmutable::parse($entry['valid_from']),
                        validTo: $validTo === null ? null : CarbonImmutable::parse($validTo),
                        source: $entry['source'],
                        sourceVersion: $entry['source_version'],
                        fetchedAt: CarbonImmutable::parse($entry['fetched_at']),
                        approvedBy: is_string($approvedBy) ? $approvedBy : null,
                    ));
                }
            }
        }

        return $table;
    }

    /** The shape the history has to have, written once because three refusals above quote it. */
    private function historyShape(): string
    {
        return 'a list of intervals, each with "valid_from", an optional "valid_to" (null while current), '
            .'"source", "source_version", "fetched_at" and a "rates" array of country => band => basis '
            .'points, or null when the installation prices at a single table';
    }

    /**
     * The active jurisdiction profile's own rates, where it carries any.
     *
     * This is what spares an operator in a shipped jurisdiction from hand-typing their own country's rates.
     * Hand-typed rates are wrong in a way nothing catches: a wrong rate looks exactly like a right one on an
     * invoice, and the mistake surfaces at the tax return rather than at the sale.
     */
    private function profileRates(): ?TaxRateMatrix
    {
        $profile = $this->profiles?->profile();

        if (! $profile instanceof SuppliesTaxRates) {
            return null;
        }

        return new TaxRateMatrix($profile->taxRates(), $profile->taxRatesValidFrom());
    }
}
