<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Moves the key of each correction issued here from `provider_id` to `correction_key`.
 *
 * `provider_id` names a provider's own document, and a document without one was issued here. Two correcting documents
 * this package issues kept the key that guards against a second document for the same event in that column, and so they
 * read as a provider's documents: the credit note for a refund of a locally raised invoice, keyed on the refunded state
 * it documents, and the correction a redeemed proration credit owes, keyed on the ledger entry and the invoice. The key
 * is the event the document corrects, which is what `correction_key` holds, unique together with the invoice it
 * corrects. Nothing else on the row changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The credit note for a refund, and only one against an invoice raised here: a provider's own credit note keeps
        // the id the provider gave it, whatever its shape.
        $this->move(
            DB::table('billing_invoices as note')
                ->join('billing_invoices as invoice', 'invoice.id', '=', 'note.credited_invoice_id')
                ->whereNotNull('invoice.order_id')
                ->whereNull('note.correction_key')
                ->where('note.provider_id', 'like', '%:credited:%')
                ->select(['note.id', 'note.provider_id']),
            'note.id',
        );

        // The correction of a redeemed proration credit, against whichever invoice it reduces. Its key names a ledger
        // entry and an invoice of this package, a shape no provider gives a document.
        $this->move(
            DB::table('billing_invoices')
                ->whereNotNull('credited_invoice_id')
                ->whereNull('correction_key')
                ->where('provider_id', 'like', 'credit-offset:%')
                ->select(['id', 'provider_id']),
            'id',
        );
    }

    /**
     * Deliberately nothing.
     *
     * The key guards against a second document for the same event, and the code before this reads it nowhere else. A
     * document that keeps it in `correction_key` after a rollback is still guarded there, and moving it back would make
     * a document issued here read as a provider's again.
     */
    public function down(): void {}

    /** Moves each document's key, in chunks by its id, so rows already moved are never read again. */
    private function move(Builder $documents, string $id): void
    {
        $documents->chunkById(500, function (Collection $rows): void {
            foreach ($rows as $row) {
                DB::table('billing_invoices')->where('id', $row->id)->update([
                    'correction_key' => $row->provider_id,
                    'provider_id' => null,
                ]);
            }
        }, $id, 'id');
    }
};
