<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Carbon\CarbonInterface;
use Pushery\Billing\Enums\SettlementDocumentType;
use Pushery\Billing\Models\InvoiceRecord;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\SubLedgerMovement;

/**
 * The platform's own record of what it owes each merchant — the sub-ledger behind one collective account.
 *
 * ## Why one account and a sub-ledger, rather than an account per merchant
 *
 * The number of merchants is unbounded. Giving each one a ledger account fills an accountant's master data
 * with rows nobody there will ever look at, and the platform already has to know per-merchant balances to
 * pay anybody — so the detail exists either way. Keeping it here and the collective figure in the books is
 * the arrangement that stays workable at any number of merchants. The alternative is configurable, for the
 * installation whose accountant expects individual creditors.
 *
 * ## Derived from the documents, never accumulated
 *
 * Every movement comes from a document, and the balance is their sum. Nothing is written down and carried
 * forward, so the sub-ledger cannot drift from the documents it claims to summarize — the failure mode a
 * stored balance has, and the one that only shows up when somebody finally reconciles.
 *
 * It reads the same documents the booking batch reads, under the same rules — including dropping one whose
 * self-billing the merchant has objected to, from the period of the objection on. That shared rule is the
 * point: a document the batch leaves out but the sub-ledger keeps would show up as a difference at month
 * end, which is exactly what the reconciliation is for.
 */
final readonly class MerchantPayablesSubLedger
{
    /**
     * The movements a set of documents produces in a period.
     *
     * A settlement raises the payable by what the merchant is owed. A payout clears it. The two are emitted
     * from one document today, which is why an ordinary transaction nets to zero — and why the cases that do
     * NOT net (a settlement issued without its payout, a correction) are the ones a reconciliation has to
     * catch.
     *
     * @param  iterable<InvoiceRecord>  $documents
     * @return list<SubLedgerMovement>
     */
    public function movementsIn(iterable $documents, CarbonInterface $periodStart): array
    {
        $movements = [];

        foreach ($documents as $document) {
            if (! $document->settlement_document_type instanceof SettlementDocumentType) {
                continue;
            }

            // The same exclusion the batch applies: an objected self-billed document has no effect as an
            // invoice from the period of the objection on, so it is not in the books and must not be here.
            if ($document->invoiceEffectVoidForPeriod($periodStart)) {
                continue;
            }

            foreach ($this->movementsOf($document) as $movement) {
                $movements[] = $movement;
            }
        }

        return $movements;
    }

    /**
     * The balance per merchant, keyed by the morph pair.
     *
     * @param  iterable<InvoiceRecord>  $documents
     * @return array<string, Money>
     */
    public function balancesIn(iterable $documents, CarbonInterface $periodStart, string $currency): array
    {
        $balances = [];

        foreach ($this->movementsIn($documents, $periodStart) as $movement) {
            $key = $movement->merchantKey();
            $running = $balances[$key] ?? Money::of(0, $currency);

            $balances[$key] = $running->plus($movement->amount);
        }

        return $balances;
    }

    /**
     * The movements one document produces.
     *
     * @return list<SubLedgerMovement>
     */
    private function movementsOf(InvoiceRecord $document): array
    {
        $amount = Money::of($document->total_minor, $document->currency);
        $number = $document->number ?? (string) $document->id;
        $date = $document->issued_at ?? $document->created_at ?? $document->freshTimestamp();

        // A correction takes back what it names — it states a positive magnitude and its role inverts it,
        // the same rule the booking marker follows, so the two can never disagree about direction.
        $raised = $document->isCorrection() ? $amount->negated() : $amount;

        $movements = [new SubLedgerMovement(
            $document->owner_type,
            $document->owner_id,
            $number,
            $date,
            $raised,
        )];

        // The payout leg exists only where the document carries the full chain. A settlement issued on its
        // own leaves the payable standing, which is the honest reading: nothing has been paid.
        if ($document->fan_gross_minor !== null) {
            $movements[] = new SubLedgerMovement(
                $document->owner_type,
                $document->owner_id,
                $number,
                $date,
                $raised->negated(),
            );
        }

        return $movements;
    }
}
