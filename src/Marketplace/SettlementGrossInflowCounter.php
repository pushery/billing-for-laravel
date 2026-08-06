<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\CountsEarnings;
use Pushery\Billing\Enums\ReversalAttribution;
use Pushery\Billing\Enums\TaxArchetype;
use Pushery\Billing\Exceptions\ReportingCounterDisabled;
use Pushery\Billing\Models\InvoiceRecord;
use Pushery\Billing\ValueObjects\CountingPeriod;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\SellerActivity;

/**
 * What actually reached a creator in a window — the GROSS inflow, counted off the settlement documents.
 *
 * ## A different basis, not a different arithmetic
 *
 * The threshold monitor counts payout-net: what the creator's supply was worth before their own tax. This
 * counts what they were actually paid, which for a standard-rated creator INCLUDES the VAT shown on their
 * credit note. One transaction, two legitimate numbers:
 *
 * | creator standing | payout net | gross inflow |
 * | ---------------- | ---------- | ------------ |
 * | small business   | 90.00      | 90.00        |
 * | standard rated   | 90.00      | 107.10       |
 *
 * A single "revenue per creator" total cannot serve both, and the one that looks obviously reusable is the
 * one already there — which is why this is a second counter on the shared seam rather than a division
 * somewhere. There is deliberately no code path deriving one from the other: `gross = net / 0.9 × 1.19` is
 * right for exactly one rate, one commission and one unmixed basket, and wrong the moment any of the three
 * moves.
 *
 * ## Why the settlement document and not the charge row
 *
 * The gross inflow is the creator's supply plus their own tax, and that sum is decided by the inbound tax
 * matrix from the standing the creator had AT THE SUPPLY. The settlement document is where that decision was
 * frozen; the charge row carries the platform's side of the same sale and never knew the creator's standing.
 * Counting the frozen figure is also what makes a re-count reproduce the original: a creator who registers
 * for VAT in March does not retroactively change what reached them in February.
 *
 * ## Asked for by NAME, not by contract
 *
 * It implements the shared seam because the machinery is the same one -- that is the whole point of the seam
 * existing. But the container binds the THRESHOLD counter to that contract, because a bare type-hint should
 * keep answering what it has always answered. A caller wanting this basis asks for this class, and the fact
 * that it has to say so is the safeguard: the two numbers are both plausible, and a silent swap between them
 * is the error this area is built to prevent.
 *
 * ## Corrections subtract, and the row does not say so
 *
 * A correcting settlement states a POSITIVE magnitude, because a negative invoice is not a thing -- its
 * meaning is carried by the document it credits. It also keeps the original's settlement type. So a naive
 * sum over the type adds a clawback to the figure it reduces, and does it in the over-reporting direction,
 * which here is not the safe error: filing more than is owed is itself a wrong return.
 *
 * ## What this deliberately does NOT count
 *
 * The fees withheld. They are their own figure and they belong in the same report, but the only stored
 * amount for them today is the platform's fee on the charge row — which is computed on a base that is being
 * corrected. Counting it here would freeze the wrong number into a reporting total, so it waits rather than
 * being approximated.
 *
 * ## And it does not decide WHO is reportable — that is a different question with a different owner
 *
 * This answers "how much", per party and per period. Whether a seller has to be reported at all turns on
 * WHAT was sold — a gate at the charge seam, whose full classification is deliberately not stored
 * ({@see SellerActivity} for why). A caller assembling a return takes the figures from here and that
 * classification from their own catalog.
 *
 * This paragraph used to say the archetype was "not derivable from these rows", and that overstated it in a
 * way that contradicted the class's own code: `countedInByArchetype()` selects `tax_archetype` off exactly
 * these documents and splits the period by it. What is genuinely absent is the reportability DECISION, not
 * the archetype — those are different things, and blurring them made the class look like it was reading a
 * column it had just declared unavailable.
 *
 * Worth saying out loud because the two feel like one job: a counter that produced both would look tidier
 * and would be quietly guessing at half of it, in a direction where over-reporting is its own violation.
 */
final readonly class SettlementGrossInflowCounter implements CountsEarnings
{
    public function __construct(private ?Repository $config = null) {}

    public function countedIn(Model $party, string $currency, CountingPeriod $period): Money
    {
        $this->assertEnabled();

        // A CORRECTION SUBTRACTS, and nothing about the row says so on its face. A correcting settlement
        // states a positive magnitude -- "this much less" -- because a negative invoice is not a thing; its
        // meaning lives in its ROLE, which is the document it credits. It also carries the same settlement
        // type as the original, so a sum over the type alone adds a clawback to the very figure it reduces.
        //
        // That is the over-reporting direction, and over-reporting here is not the safe error: filing more
        // than is owed is itself a wrong return, and it hands a tax authority personal data with no basis.
        // A creator paid 107.10 who then had 45.00 clawed back would be reported at 152.10.
        //
        // WHERE a correction lands is a CONFIGURED question, and this comment used to answer it with the
        // wrong half. It said a correction is dated the month it happened and therefore lands in the quarter
        // of the reversal, "which is why nothing here reaches back into the original period" -- and that
        // describes `reversal_period`, which is not the default. The shipped default is `original_period`,
        // and `documentsIn()` reaches back through the credited row to place the correction in the quarter
        // whose figure it undoes.
        //
        // Worth correcting rather than tidying, because this file SHIPS: a reader of the published package
        // was being told the counter never reaches back while their installation did it on every correction.
        // Prose is the one part of a package no test can contradict, which is exactly why it has to be
        // measured against the code rather than remembered from the design it was written for.
        //
        // The sign rule below is the part that holds either way, and it is the part that matters here.
        $documents = $this->documentsIn($party, $currency, $period)
            ->get(['total_minor', 'credited_invoice_id']);

        $total = 0;

        foreach ($documents as $document) {
            $total += $document->credited_invoice_id === null
                ? (int) $document->total_minor
                : -(int) $document->total_minor;
        }

        return Money::of($total, strtoupper($currency));
    }

    /**
     * The same window, split by WHAT was sold.
     *
     * A reporting rule does not ask one question of a seller's quarter — it asks it of each kind of thing
     * they sold, because the answer differs: there is no small-scale relief for commissioned work, and a
     * thousand standardized downloads are not reportable however much they came to. A single total cannot
     * be handed to a rule that branches on the kind.
     *
     * Built on the SAME query and the same sign rule as {@see countedIn()} rather than beside it. Two
     * queries over settlement documents would be two places the correction sign, the window and the
     * reissue exclusion live, and the day one of them changed the split figures would stop adding up to the
     * total the return states — with each of the two internally consistent, which is how nobody notices.
     *
     * ## The unclassified bucket is a real answer, not a leftover
     *
     * Documents whose archetype is null come back under `null`, deliberately kept apart rather than folded
     * into anything. Two things land there and both matter: a settlement issued before the classification
     * could be recorded at all, and a COLLECTIVE settlement, which covers many transactions and therefore
     * has no single archetype to carry. A caller assembling a return has to see that group and decide
     * about it; silently treating it as one kind is how a seller gets reported under a rule their sales
     * never met, or left out of one they did.
     *
     * ## A voluntary payment splits by what it was paid ON, not merely by being one
     *
     * A tip has no treatment of its own — the taxonomy delegates every consequence, reportability included,
     * to the thing it accompanied. So two tips from the same quarter can belong to opposite answers, and a
     * single `tip` line would hand the rule a total it cannot judge without choosing one of them for all of
     * it. The group key therefore carries the REFERENCE alongside the archetype, and a caller downstream
     * receives it rather than having to find it again.
     *
     * @return array<string, array{archetype: ?TaxArchetype, soldAlongside: ?TaxArchetype, gross: Money,
     *     transactions: int}> keyed by the archetype's value — suffixed with the reference where one exists
     *     — or `unclassified`
     */
    public function countedInByArchetype(Model $party, string $currency, CountingPeriod $period): array
    {
        $this->assertEnabled();

        $documents = $this->documentsIn($party, $currency, $period)
            ->get(['total_minor', 'credited_invoice_id', 'tax_archetype', 'sold_alongside_archetype']);

        $currency = strtoupper($currency);

        /** @var array<string, array{archetype: ?TaxArchetype, soldAlongside: ?TaxArchetype, gross: Money, transactions: int}> $groups */
        $groups = [];

        foreach ($documents as $document) {
            $archetype = $document->tax_archetype;
            $reference = $document->sold_alongside_archetype;
            $key = $archetype instanceof TaxArchetype ? $archetype->value : 'unclassified';

            if ($reference instanceof TaxArchetype) {
                $key .= ':'.$reference->value;
            }

            $groups[$key] ??= [
                'archetype' => $archetype,
                'soldAlongside' => $reference,
                'gross' => Money::of(0, $currency),
                'transactions' => 0,
            ];

            // The same sign rule as the whole-period total: a correction states a positive magnitude and
            // means the opposite, so summing over the type alone adds a clawback to the figure it reduces.
            $signed = $document->credited_invoice_id === null
                ? (int) $document->total_minor
                : -(int) $document->total_minor;

            $groups[$key]['gross'] = $groups[$key]['gross']->plus(Money::of($signed, $currency));

            // A correction counts as a transaction of its own, exactly as it does for the whole-period
            // figure — the reporting record asks how many settlements there were, and a reversal is one.
            $groups[$key]['transactions']++;
        }

        return $groups;
    }

    /**
     * How many settlements the creator received in the window.
     *
     * Its own count, not a length derived from anything: the reporting record asks for the number of
     * transactions beside their value, and a consumer computing it from a list of amounts would drop the
     * ones that netted to nothing.
     */
    public function transactionsIn(Model $party, string $currency, CountingPeriod $period): int
    {
        $this->assertEnabled();

        return $this->documentsIn($party, $currency, $period)->count();
    }

    /**
     * The settlements issued to this party inside the window.
     *
     * BOTH settlement document types count. Which form the creator's settlement took — a credit note the
     * platform raised, or a settlement note — is a question about the supply chain the sale ran through, and
     * the money reached them either way. Filtering to one would silently under-report every creator on the
     * other regime.
     *
     * @return Builder<InvoiceRecord>
     */
    private function documentsIn(Model $party, string $currency, CountingPeriod $period): Builder
    {
        return InvoiceRecord::query()
            ->where('owner_type', $party->getMorphClass())
            ->where('owner_id', $party->getKey())
            ->where('currency', strtoupper($currency))
            ->whereNotNull('settlement_document_type')
            // WHICH documents the period contains -- the rule lives on the model now, because the reporting
            // roster needs the same answer and used to compute a different one. See InvoiceRecord::scopePlacedIn().
            ->placedIn($period, $this->attribution());
    }

    /**
     * Refuse outright on an installation that has switched this counter off.
     *
     * On EVERY public reading rather than at the one that looked most important. A counter with one guarded
     * door is a counter with an unguarded one, and which door a caller happens to use is not a security
     * property.
     *
     * A refusal rather than a zero. Zero is a real reporting answer -- this seller received nothing -- and a
     * disabled counter handing it back would let a platform file a return saying every seller earned
     * nothing, with nothing red and every figure internally consistent. That is the shape this package
     * keeps finding in itself: an absence read as an answer.
     *
     * The default is ON, because the default has to be what the package already did. A switch that changes
     * behavior on upgrade is not a switch.
     *
     * @throws ReportingCounterDisabled
     */
    private function assertEnabled(): void
    {
        if ($this->config?->get('billing.tax_counters.dac7.enabled', true) === false) {
            throw ReportingCounterDisabled::forGrossInflow();
        }
    }

    /** Which window a reversal reduces — read from the one place the rule lives. */
    private function attribution(): ReversalAttribution
    {
        return ReversalAttribution::configured($this->config);
    }
}
