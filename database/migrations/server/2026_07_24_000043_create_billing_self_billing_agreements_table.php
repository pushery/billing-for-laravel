<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A creator's standing agreement that the platform may settle them by self-billing.
 *
 * A self-billed document is only an invoice if both sides agreed to the arrangement BEFORE it — a document
 * issued without that agreement is not an invoice, carries no input-tax effect, and cannot be healed after
 * the fact; the only cure is re-issuing once the agreement exists. So the agreement is a real record with a
 * real date, not a checkbox in a UI: a job or a console command that never saw the UI must still be refused.
 *
 * It is a FRAMEWORK agreement — one record covers every future settlement, not one per transaction — and it
 * is versioned by appending, never by editing. A changed clause is a NEW row; the old one stays exactly as
 * it stood, because it is the evidence for which wording was in force when a past document was produced.
 * `accepted_at` is the ex-ante anchor a document is checked against; it is emphatically not `created_at`,
 * which only records when the row was written. `revoked_at` terminates the arrangement going forward and
 * never touches documents already issued.
 *
 * The morph is nullable and carries an erasure stamp, like an invoice and like the tax standing: this row
 * justifies the tax treatment of documents the law requires the platform to keep, so it outlives the
 * creator's own data with the stamp standing in for the identity that was erased.
 *
 * The neutral name is deliberate — "Gutschrift" is the German document, but self-billing is the concept
 * everywhere. Server-only, reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_self_billing_agreements', function (Blueprint $table): void {
            $table->id();
            // The creator IS the merchant, and the morph follows the merchant erasure axis exactly —
            // merchant_type/merchant_id/merchant_erased_at — so the eraser can retain-and-stamp this row
            // rather than delete it, the same as the tax standing and the routed charge.
            $table->nullableMorphs('merchant');
            $table->timestamp('merchant_erased_at')->nullable();

            // The ex-ante anchor: a document for a supply is authorized only by an agreement accepted at or
            // before that supply's date. Not created_at, which merely says when the row was written.
            $table->timestamp('accepted_at');
            // Which clause/terms wording was accepted, so a past document can name the version in force then.
            $table->string('terms_version');
            // The proof protocol: what was shown, in which language, confirmed from which source.
            $table->json('evidence')->nullable();
            // Termination of the framework going forward. Null is a live agreement; a set value sends every
            // LATER supply to the fallback lane while leaving earlier documents untouched.
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();

            // The read path: every settlement asks for one merchant's agreements. Ordered by accepted_at so
            // the applicable version is found without a scan.
            $table->index(['merchant_type', 'merchant_id', 'accepted_at'], 'billing_self_billing_agreements_merchant_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_self_billing_agreements');
    }
};
