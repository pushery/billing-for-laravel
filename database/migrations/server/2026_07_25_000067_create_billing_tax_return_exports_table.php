<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A produced return file, kept as the document it is.
 *
 * The file itself lands wherever the operator points the export. This row is what survives the file being
 * moved, re-generated, or quietly edited before it was filed: when it was produced, what it contained, and a
 * fingerprint of the exact bytes. Without it, "which figures did we actually file for Q2" has no answer a
 * year later — only a file somebody may or may not have overwritten since.
 *
 * Rows accumulate rather than replace. A second export of the same period is a second row, because the
 * interesting fact is precisely that the period was produced twice and whether the two agree.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_tax_return_exports', function (Blueprint $table): void {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('quarter');
            $table->string('period_label', 16);
            $table->string('currency', 3);
            $table->timestamp('generated_at');
            $table->unsignedInteger('line_count');
            $table->bigInteger('net_minor');
            $table->bigInteger('tax_minor');
            // The bytes' fingerprint, so a re-run can be compared against what was filed without keeping two
            // copies of the file around to diff.
            $table->string('checksum', 64);
            $table->text('contents');
            $table->string('written_to')->nullable();
            $table->timestamps();

            $table->index(['year', 'quarter', 'currency'], 'billing_tax_return_exports_period_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_tax_return_exports');
    }
};
