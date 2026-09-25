<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Pushery\Billing\Enums\ReversalAttribution;
use Pushery\Billing\Exceptions\SellerModelMissing;
use Pushery\Billing\Models\InvoiceRecord;
use Pushery\Billing\ValueObjects\CountingPeriod;
use Pushery\Billing\ValueObjects\SellerPeriodReport;
use Pushery\Billing\ValueObjects\SellerQuarterFigures;

/**
 * A whole reporting period, seller by seller — the loop the pieces were waiting for.
 *
 * Every part of this existed and none of them were joined. {@see SellerReportingRun} answers ONE seller;
 * the three counters answer ONE seller and ONE window; {@see ReportingProfile} states which fields a
 * seller's record needs. A reporting period asks all of that about EVERY seller who was active in it, and
 * nothing did.
 *
 * ## Who is in the period, and why it is decided by what they sold
 *
 * The sellers are read off the records of their sales — not off the merchant registry. There are two kinds:
 * the settlement documents of a commission chain, and the sales the platform arranged as an intermediary,
 * which produce no settlement document and are read off the sale itself. The registry lists everyone who
 * ever onboarded, so a run built from it would produce a row of zeros for every merchant who sold nothing,
 * and a zero is a REPORTABLE ANSWER: it states that a seller received nothing, which is a claim about their
 * year rather than the absence of one.
 *
 * Reading the sales also makes the figures and the roster come from the same records. A roster from the
 * registry and figures from the sales can disagree — a seller present with no figures, or figures with no
 * seller — and both disagreements look like data problems rather than like the two queries they are.
 *
 * ## What it does NOT assemble
 *
 * The seller's own record — name, address, the identifiers a statute names. The package holds the field
 * CATALOG (which fields, on what basis) and the completeness rule; it does not hold the values, because the
 * seller master data belongs to the consuming application. So this returns what the package can know —
 * who, what they sold, whether that is reportable, and the three figures per quarter — and a caller joins
 * their own record to it.
 *
 * That split is the reason the return type carries the reportability verdict per line rather than one
 * verdict per seller: the field basis turns on it (`ReportingProfile::fieldsFor(..., reportable:)`), and a
 * seller who sold both commissioned work and downloads has two answers.
 */
final readonly class SellerReportingPeriod
{
    public function __construct(
        private SellerReportingRun $run,
        private SettlementGrossInflowCounter $inflow,
        /**
         * Only to read WHICH window a reversal belongs to.
         *
         * Optional and defaulted so a run built by hand in a script answers the way the container's does --
         * `ReversalAttribution::configured(null)` is the shipped default, which is the same thing the
         * counters fall back to.
         */
        private ?Repository $config = null,
        /**
         * The sales arranged as an intermediary. They produce no settlement document, so without them a
         * seller who only sold that way would be missing from every period.
         *
         * Resolved where it is read when none is given, and not for convenience: the container never hands
         * it over, because Laravel prefers a nullable parameter's default to resolving a class nothing bound.
         * A period that skipped these sales would under-report, and no caller wants that.
         */
        private ?IntermediatedSalesCounter $intermediated = null,
    ) {}

    /**
     * Every seller active in the period, with their lines and their four quarters.
     *
     * Ordered by the seller's stored morph pair so two runs over the same data produce the same list. A
     * report whose row order depends on how the database felt is one nobody can diff against last year's —
     * the same reason {@see SellerReportingRun} sorts its lines.
     *
     * @return list<SellerPeriodReport>
     */
    public function reportsFor(int $year, string $currency): array
    {
        $reports = [];

        foreach ($this->sellersIn($year, $currency) as $seller) {
            $reports[] = new SellerPeriodReport(
                seller: $seller,
                lines: $this->run->linesFor($seller, $currency, CountingPeriod::year($year)),
                quarters: $this->quartersFor($seller, $year, $currency),
            );
        }

        return $reports;
    }

    /**
     * The three figures, per quarter, from the counters that own them.
     *
     * Read rather than recomputed, and that is the load-bearing part. Each of these has a rule behind it —
     * a correction's sign, which window a reversal belongs to, the ceiling a confirmation is capped
     * against — and a run that summed anything itself would be a second place for those rules. The two
     * copies would agree until one of them was changed, which is the point at which nobody is checking.
     *
     * @return array<int, SellerQuarterFigures> keyed 1..4
     */
    private function quartersFor(Model $seller, int $year, string $currency): array
    {
        $quarters = [];

        foreach ([1, 2, 3, 4] as $quarter) {
            $period = CountingPeriod::quarter($year, $quarter);

            $quarters[$quarter] = new SellerQuarterFigures(
                quarter: $quarter,
                // What reached the seller, from both kinds of record: the settlement documents of the chain,
                // and the sales the platform arranged as an intermediary, which produce no such document.
                grossInflow: $this->inflow->countedIn($seller, $currency, $period)
                    ->plus($this->intermediated()->countedIn($seller, $currency, $period)),
                transactions: $this->inflow->transactionsIn($seller, $currency, $period)
                    + $this->intermediated()->transactionsIn($seller, $currency, $period),
                // Not simply what the platform kept. "Separately withheld fees" asks what the SELLER was
                // charged. Under a commission chain the answer is nothing: the platform's margin is the
                // difference between two supplies, never billed to them, and reporting it would invent a
                // service relationship the books do not contain. Under intermediation the platform charges
                // the seller for arranging the sale, and that fee is exactly what the field is for. So the
                // field carries the intermediated fee and nothing from the chain, per sale rather than per
                // seller, because one seller can have sold both ways.
                feesWithheld: $this->intermediated()->feesIn($seller, $currency, $period),
            );
        }

        return $quarters;
    }

    /**
     * The sellers a period's settlement documents and intermediated sales name, resolved to models.
     *
     * A stored morph type is a class name or a morph-map alias for one, and a consumer that renames or
     * removes a model leaves rows naming a class that is gone. That is asked BEFORE the model is fetched
     * rather than caught afterwards — the same order `RoutedChargeLedger` uses, and for the same reason: a
     * missing class raises an Error rather than answering null, and catching it would bury the condition in
     * a handler that reads like defensive noise.
     *
     * A row whose class has gone is SKIPPED, not guessed at. It cannot be reported — there is no seller to
     * report — and inventing one from a stale class name would file a record about nobody.
     *
     * @return list<Model>
     */
    private function sellersIn(int $year, string $currency): array
    {
        $period = CountingPeriod::year($year);

        $pairs = InvoiceRecord::model()::query()
            ->whereNotNull('settlement_document_type')
            ->where('currency', strtoupper($currency))
            ->whereNotNull('owner_type')
            ->whereNotNull('owner_id')
            // THE SAME window the figures use, not a second reading of it. This filtered on `issued_at`
            // alone, while the counters place a correction by the configured attribution — under the shipped
            // default, by the date of the document it credits. A seller whose only activity in a year was a
            // correction of an older settlement therefore entered the roster and received a row of ZEROS,
            // because the figures had placed that correction in the previous year.
            //
            // A row of zeros is not an empty answer. It states that a seller received nothing, which is a
            // claim about their year — and this class documents itself as avoiding exactly that.
            ->placedIn($period, ReversalAttribution::configured($this->config))
            ->distinct()
            ->get(['owner_type', 'owner_id']);

        // Both kinds of record name sellers, and a seller named by both is one seller. Keyed by the stored
        // pair, then sorted, so the roster reads the same on every run.
        $byPair = [];

        foreach ($pairs as $pair) {
            $byPair[$pair->owner_type.'#'.$pair->owner_id] = ['type' => (string) $pair->owner_type, 'id' => (string) $pair->owner_id];
        }

        foreach ($this->intermediated()->sellersIn($period, $currency) as $pair) {
            $byPair[$pair['type'].'#'.$pair['id']] = $pair;
        }

        // Numeric ids compare as numbers, as the database ordered them before this list had two sources, so
        // a report keeps its row order across versions; anything else (a UUID) compares as text.
        uasort($byPair, static fn (array $a, array $b): int => ($a['type'] <=> $b['type']) ?: (is_numeric($a['id']) && is_numeric($b['id'])
            ? (int) $a['id'] <=> (int) $b['id']
            : strcmp($a['id'], $b['id'])));

        $sellers = [];

        foreach ($byPair as $pair) {
            $type = $pair['type'];
            $class = Relation::getMorphedModel($type) ?? $type;
            if (! class_exists($class)) {
                continue;
            }
            if (! is_subclass_of($class, Model::class)) {
                continue;
            }

            // WITHOUT global scopes, and that is the whole point of the call rather than a flourish. A
            // consuming application that soft-deletes its sellers, or scopes them to a tenant, would
            // otherwise have `find()` answer null for a seller who is merely hidden -- and a whole year of
            // their activity would drop out of the reporting period, silently, in the under-reporting
            // direction. A closed account still owes a return for the year it was open.
            $seller = $class::query()->withoutGlobalScopes()->find($pair['id']);

            if (! $seller instanceof Model) {
                // The class exists, the id came off a settlement document, and money was paid against it --
                // so there is no reading under which this is an empty answer. Skipping it would remove a
                // seller from a filing without saying so; the only honest response is to stop.
                throw SellerModelMissing::for($type, $pair['id']);
            }

            $sellers[] = $seller;
        }

        return $sellers;
    }

    private function intermediated(): IntermediatedSalesCounter
    {
        return $this->intermediated ?? Container::getInstance()->make(IntermediatedSalesCounter::class);
    }
}
