<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What was reported about sellers for a period, kept as the exact bytes that were produced.
 *
 * ## Why not the tax-return archive's table
 *
 * That one holds the same DISCIPLINE and a different SHAPE. `billing_tax_return_exports.quarter` is not
 * nullable, and neither are its `net_minor` and `tax_minor`. A seller report is an ANNUAL record with no
 * quarter of its own, no tax total, and no country/rate lines — there is no value it could honestly write
 * into those columns, and a zero would be a claim rather than a placeholder.
 *
 * Widening them was the alternative and it costs the wrong side: every VAT row would lose a `NOT NULL`
 * guard so that a different format could share the table. The reusable part is the discipline — immutable,
 * a second run is a second row, a fingerprint over the exact bytes — and that costs one migration here
 * while owing the VAT side nothing.
 *
 * ## Why the bytes and not the figures
 *
 * A file can be moved, regenerated, overwritten or edited between production and filing, and none of that
 * leaves a trace. "Which figures did we actually report for that year" then has no answer — only a file
 * somebody may have touched. Storing the figures instead would answer a different question: it would say
 * what the package believes today, which is exactly what a later dispute is about.
 *
 * ## The format version is a column
 *
 * A record that does not say which version of a format it was built to is unverifiable years later, and the
 * version moves without the data moving. Keeping it beside the bytes means a reader never has to work out
 * which release produced them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_reporting_exports', function (Blueprint $table): void {
            $table->id();
            $table->unsignedSmallInteger('period_year');
            $table->string('currency', 3);
            // WHICH format, and WHICH version of it — two columns rather than one string, because the pair
            // is asked separately: "show me every DAC7 record" and "which ones predate version 2".
            $table->string('format');
            $table->string('format_version');
            $table->timestamp('generated_at');
            // How many sellers the record covers. The one figure kept beside the bytes, because "did that
            // year really have four sellers?" is the question somebody asks before opening the file.
            $table->unsignedInteger('seller_count');
            $table->string('checksum', 64);
            $table->longText('contents');
            $table->string('written_to')->nullable();
            $table->timestamps();

            // No unique key on the period. A second run of one year is NORMAL — figures move as late
            // corrections land — and the interesting fact is that it happened and whether the two agree. A
            // unique key would force the second run to overwrite the first, destroying the only evidence
            // that anything changed.
            $table->index(['period_year', 'currency', 'format'], 'billing_reporting_exports_period_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_reporting_exports');
    }
};
