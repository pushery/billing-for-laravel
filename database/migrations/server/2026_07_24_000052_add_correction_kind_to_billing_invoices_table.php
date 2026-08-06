<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which of the two correcting documents a correction is — the role the written type code hangs on.
 *
 * A correction that reverses a booked turnover and a correction that amends a specific earlier invoice are
 * different documents with different obligations, and a tax authority reads them apart by their type code
 * (381 against 384). Until now both were one boolean, so both were written as 381 and the amendment could
 * not exist. Deriving the distinction later is not possible: nothing in the row says which one was meant.
 *
 * Null on every row that corrects nothing, which is every ordinary invoice and every settlement — the column
 * answers "which correction is this", not "is this a correction", a question the origin reference already
 * answers. Frozen once written: a document that changed which kind of correction it is would be a different
 * document with the same number. Server-only, reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->string('correction_kind')->nullable()->after('fan_gross_minor');
        });
    }

    public function down(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->dropColumn('correction_kind');
        });
    }
};
