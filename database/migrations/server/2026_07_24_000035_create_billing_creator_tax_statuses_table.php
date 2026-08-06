<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A creator's tax standing over TIME, as a series of intervals rather than a column on the creator.
 *
 * The difference is the whole design. A single overwritable column answers "what is their status", which
 * is not the question any document ever asks: a document asks what their status was on the day the supply
 * happened. With a column, a creator who changes status in March silently rewrites how every document
 * issued in January should have looked — and nothing can reconstruct the old answer, because the old value
 * is gone.
 *
 * So there is no current-status column anywhere. The current status is a query with a moment in it.
 *
 * `created_at` is emphatically not `effective_from`: a status can be recorded weeks after it started
 * applying, and the gap between the two is what makes a retroactive change visible as one.
 *
 * The morph is nullable and carries an erasure stamp, like an invoice: this row is the evidence that
 * justifies the tax treatment of documents the law requires the platform to keep, and deleting it would
 * leave those documents unexplainable to the auditor who asks about them.
 *
 * Server-only, reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_creator_tax_statuses', function (Blueprint $table): void {
            $table->id();
            $table->nullableMorphs('merchant');
            $table->timestamp('merchant_erased_at')->nullable();

            $table->string('status');
            $table->timestamp('effective_from');
            // Null is an OPEN interval — the state that still applies. Exactly one row per creator may
            // carry it, which is what the partial-index guard in the model layer holds.
            $table->timestamp('effective_to')->nullable();

            $table->string('source');
            // The anchor for the evidence: a check protocol, the accepted declaration text and its version,
            // or the transaction that triggered an automatic move.
            $table->string('evidence_ref')->nullable();
            // When the attestation expires, if it does. Null means no expiry clock — a status the system
            // derived itself does not go stale the way one somebody declared does.
            $table->timestamp('attested_until')->nullable();

            $table->timestamps();

            // One interval per creator per start moment. Two rows starting at the same instant are two
            // contradictory answers to the same question, and whichever a query returned would be arbitrary.
            //
            // It is also the READ path — every document asks for one creator's status at one moment, which
            // is exactly this prefix. A separate lookup index over the same three columns would be a second
            // copy of this one: paid for on every write, never chosen by the planner over the unique.
            $table->unique(['merchant_type', 'merchant_id', 'effective_from'], 'billing_creator_tax_statuses_interval_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_creator_tax_statuses');
    }
};
