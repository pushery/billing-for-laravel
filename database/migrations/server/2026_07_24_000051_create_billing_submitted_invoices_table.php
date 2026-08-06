<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A creator's OWN invoice, submitted through the fallback lane — the path a creator takes when the platform
 * does not (or no longer) self-bills them, after an objection or a terminated self-billing agreement.
 *
 * It is a first-class object, not an attachment to a transaction: the platform receives it, reconciles its
 * amounts against what the creator actually earned that period, and releases the payout only once it passes.
 * The reconciliation is the point — without it the platform pays out what the creator WRITES, not what they
 * earned. The issuer's own invoice number is unique per issuer so the same invoice cannot be submitted, and
 * paid, twice.
 *
 * Server-only. The whole lane is inert while marketplace is off — a single-seller install has no inbox.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_submitted_invoices', function (Blueprint $table): void {
            $table->id();

            // The creator who submitted it. Nullable because this is a RETAINED record: on the creator's
            // erasure the document is kept but UNLINKED — the owner morph is nulled and owner_erased_at
            // stamped — exactly like an invoice, so a valid financial document survives with no person on it.
            $table->string('owner_type')->nullable();
            $table->unsignedBigInteger('owner_id')->nullable();

            // What the invoice says: the issuer's own number, its net and tax, its currency. The amounts are
            // whatever the ingest parsed (or an operator keyed in a manual-review case); the reconciliation
            // decides whether they may be paid.
            $table->string('issuer_invoice_number');
            $table->unsignedBigInteger('net_minor');
            $table->unsignedBigInteger('tax_minor');
            $table->string('currency', 3);

            // Where it came from and when it arrived — evidence, and the clock the six-month view runs on.
            $table->string('source')->nullable();
            $table->timestamp('received_at');

            // The review: its state and the per-field findings, so a creator sees what is missing rather than
            // a bare "invalid". Pending until reconciled; the payout path skips anything not Passed.
            $table->string('review_state')->default('pending');
            $table->json('findings')->nullable();

            // The unlink stamp: on the creator's erasure this document is kept (a financial record) but
            // unlinked from them — owner_type/owner_id are tombstoned and this marks when, exactly like an
            // invoice. Retained, never deleted.
            $table->timestamp('owner_erased_at')->nullable()->index();

            $table->timestamps();

            // Dedup: the same issuer cannot submit the same invoice number twice. The index name is given
            // explicitly — the auto-generated one exceeds MySQL's 64-character identifier limit.
            $table->unique(['owner_type', 'owner_id', 'issuer_invoice_number'], 'billing_submitted_invoices_dedup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_submitted_invoices');
    }
};
