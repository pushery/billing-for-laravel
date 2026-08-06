<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a voluntary payment was paid ON — the one fact a tip has none of its own.
 *
 * The taxonomy says a tip delegates every consequence to the thing it accompanied, whether it is reportable
 * included. That reference reached the classifier, decided the place of supply and the rate band, and then
 * ended with the request: only the RESULTS were frozen on the document. Which is enough for the two
 * consequences the document itself states, and not enough for the one asked later.
 *
 * Reportability is asked later, by a run over a whole period, from the documents. With nothing recording
 * what the tip accompanied, that run sees `tax_archetype = tip` and answers from the tip alone — the exact
 * question the taxonomy declined to answer without the reference. A tip on commissioned work then reports as
 * standardized, which is the under-reporting direction and the sanctioned one.
 *
 * Nullable, and the nullability is not a convenience. Almost no document has a reference because almost no
 * archetype delegates: an ordinary sale answers for itself, and a null here means "this needed nothing",
 * not "this is missing something". Rows written before this column existed keep their null for the honest
 * reason — a tip settled last quarter genuinely did not record what it was paid on, and inventing a
 * plausible reference would put a reporting decision on evidence that was never collected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->string('sold_alongside_archetype')->nullable()->after('tax_archetype');
        });
    }

    public function down(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->dropColumn('sold_alongside_archetype');
        });
    }
};
