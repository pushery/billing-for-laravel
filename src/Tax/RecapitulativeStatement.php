<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Pushery\Billing\Contracts\SuppliesMonthlyRecapitulativeStatement;
use Pushery\Billing\Enums\RecapitulativeSupplyKind;
use Pushery\Billing\Exceptions\CurrencyMismatch;
use Pushery\Billing\Exceptions\RecapitulativeStatementIncomplete;
use Pushery\Billing\Invoicing\EnInvoiceTaxCategory;
use Pushery\Billing\Models\InvoiceRecord;
use Pushery\Billing\ValueObjects\RecapitulativeStatementLine;

/**
 * The lines of a recapitulative statement: what each business in another member state bought under the
 * reverse charge in a period.
 *
 * ## Who is in it
 *
 * Exactly the sales {@see InvoiceRecord::isUnionReverseChargeSale()} names, the same population the booking
 * export writes the buyer's VAT id for. A sale the consumer one-stop-shop return declares is never among
 * them: the placement that sends a sale to that return never charges it in reverse.
 *
 * ## Per buyer and kind, in the period the document was issued
 *
 * A line is one VAT id and one kind of supply, with the taxable amounts summed. Goods and services are told
 * apart by the question the document's e-invoice asks, so the two cannot disagree. A correction counts in the
 * period it is issued in, with its sign: a change to the taxable amount is reported for the period in which it
 * is made (Article 265(2) of Directive 2006/112/EC), never written back into a statement already filed. A
 * restatement of a sale already stated is skipped, as it is everywhere a sum is taken.
 *
 * ## A sale without the buyer's VAT id is refused, not dropped
 *
 * The statement is a list of VAT ids, and a reverse-charged sale without one cannot be put on it. Leaving it
 * out would make the statement short with nothing looking wrong, so the documents are named instead.
 *
 * Nothing national lives here: when the statement is due is the jurisdiction profile's answer.
 */
final readonly class RecapitulativeStatement
{
    /**
     * The lines for one period, sorted by VAT id and kind.
     *
     * @param  iterable<InvoiceRecord>  $documents  the documents issued in the period, corrections included
     * @return list<RecapitulativeStatementLine>
     *
     * @throws RecapitulativeStatementIncomplete when a reverse-charged sale names no VAT id
     */
    public function linesFor(iterable $documents): array
    {
        /** @var array<string, RecapitulativeStatementLine> $lines */
        $lines = [];
        $currency = null;
        $withoutVatId = [];

        foreach ($documents as $document) {
            if ($document->isReissue() || ! $document->isUnionReverseChargeSale()) {
                continue;
            }

            // One statement covers one currency, refused here for the reason PeriodicTaxReturn gives: the
            // figures are bare minor units, and two currencies added are a sum in no unit at all.
            $currency ??= $document->currency;

            if ($document->currency !== $currency) {
                throw CurrencyMismatch::between($currency, (string) $document->currency);
            }

            $vatId = $document->buyerVatId();

            if ($vatId === null) {
                $withoutVatId[] = $document->number ?? '#'.$document->id;

                continue;
            }

            $kind = EnInvoiceTaxCategory::isGoods($document->tax_archetype)
                ? RecapitulativeSupplyKind::Goods
                : RecapitulativeSupplyKind::Services;
            $net = $document->subtotal_minor ?? 0;
            // A correction states positive magnitudes and its role inverts them, applied once, here.
            $line = new RecapitulativeStatementLine($vatId, $kind, $document->isCorrection() ? -$net : $net);
            $existing = $lines[$line->key()] ?? null;

            $lines[$line->key()] = $existing instanceof RecapitulativeStatementLine
                ? new RecapitulativeStatementLine($vatId, $kind, $existing->netMinor + $line->netMinor)
                : $line;
        }

        if ($withoutVatId !== []) {
            throw RecapitulativeStatementIncomplete::withoutVatId($withoutVatId);
        }

        ksort($lines);

        return array_values($lines);
    }

    /**
     * The net of the supplies of goods among these documents, reductions included.
     *
     * What a profile's limit on a quarterly statement is measured against
     * ({@see SuppliesMonthlyRecapitulativeStatement}). The limit is about what was supplied, so a sale counts
     * whether or not it names a VAT id yet: a missing id must not keep a seller under it.
     *
     * @param  iterable<InvoiceRecord>  $documents  the documents issued in one quarter, in one currency
     */
    public function goodsNetMinor(iterable $documents): int
    {
        $net = 0;

        foreach ($documents as $document) {
            if ($document->isReissue()
                || ! $document->isUnionReverseChargeSale()
                || ! EnInvoiceTaxCategory::isGoods($document->tax_archetype)) {
                continue;
            }

            $amount = $document->subtotal_minor ?? 0;
            $net += $document->isCorrection() ? -$amount : $amount;
        }

        return $net;
    }
}
