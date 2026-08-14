<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Builder;
use Pushery\Billing\Enums\DocumentSeries;
use Pushery\Billing\Exceptions\ReportingCounterDisabled;
use Pushery\Billing\Models\InvoiceRecord;
use Pushery\Billing\ValueObjects\CountingPeriod;
use Pushery\Billing\ValueObjects\Money;

/**
 * What BUYERS paid, per subdivision of a destination country — the early warning a national total cannot be.
 *
 * ## Why this is a third counter and not a view of the other two
 *
 * The package already counts two things and neither of them answers this. The reporting counter measures
 * what reached a SELLER; the small-business monitor measures what a supply was worth to a creator. This one
 * measures what a BUYER paid, which on one 119.00 sale is 119.00 where the other two see 90.00 — different
 * base, different party, different question.
 *
 * Deriving it would be the tempting shortcut and it is wrong on arithmetic rather than on principle: a
 * reconstruction like `gross = settled / 0.9 * 1.19` holds only for one unmixed basket at one rate with a
 * purely proportional fee, and is wrong for every other sale — quietly, because both inputs are correct.
 *
 * ## Why it counts while the market is closed
 *
 * The obligation it warns about is reached by crossing a threshold, and a platform learns it crossed one
 * from figures it was already keeping. A counter that started when the market opened would produce its
 * first useful number after the first year that could have breached — which is the year it exists for. So
 * it is independent of any geoblock, and its own switch is independent of the other counters'.
 *
 * ## `unknown` is a bucket, never a guess
 *
 * A sale whose subdivision was never settled is counted under `unknown` rather than attributed anywhere. A
 * guessed state is the one kind of wrong figure that does active harm here: it does not merely understate a
 * threshold, it raises one in a place the platform never sold into.
 *
 * ## Nothing here is American
 *
 * The word "state" does not appear, because the mechanism is not: a subdivision-level obligation exists in
 * more than one country and the package has no business naming one. Which country's subdivisions matter is
 * the caller's question, asked by passing the country. Only the config key carries the US wording, because
 * that is the switch an operator looks for.
 */
final readonly class SubdivisionGrossSalesCounter
{
    /** The bucket a sale with no settled subdivision falls into — a name, so it cannot be read as a code. */
    public const string UNKNOWN = 'unknown';

    public function __construct(private ?Repository $config = null) {}

    /**
     * Buyer gross per subdivision, for one country, currency and window.
     *
     * @return array<string, Money> subdivision code => gross, with {@see self::UNKNOWN} for the unsettled
     *                              ones. Sorted by code so two runs produce the same report; `unknown` is
     *                              LAST because it is not a place and sorting it among the codes would put
     *                              it in the middle of a list a reader scans
     *
     * @throws ReportingCounterDisabled when the installation is not counting this at all
     */
    public function countedIn(string $country, string $currency, CountingPeriod $period): array
    {
        return $this->tally($country, $currency, $period)[0];
    }

    /**
     * How many sales stand behind those figures, per subdivision.
     *
     * Beside the amounts rather than derived from them, and for the reason every counter in this package
     * keeps its own: a threshold can be stated as an amount OR as a number of transactions, and several
     * jurisdictions state it as both.
     *
     * @return array<string, int>
     *
     * @throws ReportingCounterDisabled
     */
    public function transactionsIn(string $country, string $currency, CountingPeriod $period): array
    {
        return $this->tally($country, $currency, $period)[1];
    }

    /**
     * Both figures in one walk over the typed columns.
     *
     * Summed in PHP rather than by the database, and that is a decision about types rather than a
     * performance one: a raw `sum()` comes back as an attribute no model declares, so nothing can say
     * whether it is an int, a string or a float — and each engine here answers differently. The column
     * itself is typed, the window is one period, and money is added by {@see Money} rather than by whatever
     * a driver decided the aggregate was.
     *
     * @return array{0: array<string, Money>, 1: array<string, int>}
     *
     * @throws ReportingCounterDisabled
     */
    private function tally(string $country, string $currency, CountingPeriod $period): array
    {
        $this->assertEnabled();

        $code = strtoupper($currency);
        $gross = [];
        $counts = [];

        foreach ($this->salesIn($country, $code, $period)->get(['destination_subdivision', 'total_minor']) as $sale) {
            // Null and '' are two spellings of one absence, and a bucket per spelling would be two buckets
            // that are each short.
            $subdivision = is_string($sale->destination_subdivision) && $sale->destination_subdivision !== ''
                ? $sale->destination_subdivision
                : self::UNKNOWN;

            $gross[$subdivision] = ($gross[$subdivision] ?? Money::zero($code))->plus(new Money($sale->total_minor, $code));
            $counts[$subdivision] = ($counts[$subdivision] ?? 0) + 1;
        }

        // Sorted so two runs produce the same report, with `unknown` LAST: it is not a place, and sorted
        // among the codes it would sit in the middle of a list a reader scans down.
        $order = static fn (string $a, string $b): int => match (true) {
            $a === self::UNKNOWN => 1,
            $b === self::UNKNOWN => -1,
            default => strcmp($a, $b),
        };

        uksort($gross, $order);
        uksort($counts, $order);

        return [$gross, $counts];
    }

    /**
     * The sales in scope: buyer receipts into this country, in this currency, in this window.
     *
     * A currency is a BUCKET and never converted. Two currencies summed at any rate produce a figure that
     * was true on the day the rate was read and never again, and a threshold is a legal line rather than an
     * estimate — so a caller asks per currency and gets an answer that means one thing.
     *
     * @return Builder<InvoiceRecord>
     */
    private function salesIn(string $country, string $currency, CountingPeriod $period): Builder
    {
        return InvoiceRecord::query()
            ->where('document_series', DocumentSeries::BuyerReceipt->value)
            ->where('currency', strtoupper($currency))
            ->where('destination_country', strtoupper($country))
            // The restatement of a receipt is a SECOND document about one sale, and counting it would state
            // the buyer paid twice. The same predicate every aggregate in the package asks.
            ->whereNull('reissue_of_invoice_id')
            ->whereNull('credited_invoice_id')
            ->whereBetween('issued_at', [$period->from, $period->until]);
    }

    /**
     * @throws ReportingCounterDisabled when the switch is off
     */
    private function assertEnabled(): void
    {
        if ($this->config?->get('billing.tax_counters.us_state_gmv.enabled', false) !== true) {
            throw ReportingCounterDisabled::forSubdivisionSales();
        }
    }
}
