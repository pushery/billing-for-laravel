<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a settlement document was made available, when its recipient was told, and when they fetched it.
 *
 * A document sitting in a database is not a delivered one. What makes it delivered is that it reached the
 * recipient's reach AND that they were told — and the only thing that can ever evidence either is a record
 * written at the moment it happened. Without one there is no answer to "when did they get it", which is the
 * question every dispute about a deduction date or a objection window turns on.
 *
 * Append-only, one row per event rather than three timestamp columns on the document. Three columns would
 * make a second notification overwrite the first, and a retrieval count a number nobody can audit; separate
 * rows keep every occurrence, which is what an evidentiary log is for.
 *
 * Anchored on the document NUMBER, not only the row id, so the log still says what it is about when it is
 * produced on its own — the situation it exists for.
 *
 * The merchant morph is nullable and paired with an erasure stamp, exactly like the charges and agreements
 * beside it: the log outlives the person named on it, because it is the evidence that the documents about
 * them were validly issued, and those are kept for years. Erasure unlinks it rather than deleting it.
 *
 * Deliberately data-sparse: time, channel and a recipient identifier. No address of the fetch, no browser
 * string — the log proves delivery, and neither of those is evidence of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_document_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->string('document_number')->index();
            $table->nullableMorphs('merchant');
            $table->string('event');
            $table->string('channel')->nullable();
            $table->string('recipient')->nullable();
            $table->string('outcome')->nullable();
            $table->text('detail')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('merchant_erased_at')->nullable();
            $table->timestamps();

            // The evidentiary read: every event for one document, in the order they happened.
            $table->index(['document_number', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_document_deliveries');
    }
};
