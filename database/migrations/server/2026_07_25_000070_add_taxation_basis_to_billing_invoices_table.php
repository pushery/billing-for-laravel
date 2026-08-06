<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * On what basis the seller was taxed when this supply happened.
 *
 * Frozen for the reason every tax characteristic on this table is frozen: a seller's basis moves, and a
 * document re-derived from today's situation would quietly restate what an old one said. Nullable, because
 * a single-seller installation has one seller whose basis lives in configuration and needs no per-document
 * copy — an existing row that never had this question is not missing an answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->string('taxation_basis', 24)->nullable()->after('recipient_tax_status');
        });
    }

    public function down(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->dropColumn('taxation_basis');
        });
    }
};
