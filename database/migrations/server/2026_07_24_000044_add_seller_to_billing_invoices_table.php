<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The seller party, frozen onto each document.
 *
 * Until now the seller was always the platform and so was never stored — the writers read `billing.company`
 * at render time. A marketplace reverses that on a self-billed document, where the seller is the creator,
 * and a document must keep naming whoever it named when it was issued even after the config or the creator
 * changes. So the seller becomes a per-document snapshot, exactly like the buyer beside it.
 *
 * It is a JSON column and it is frozen: a document already issued cannot have its seller rewritten. A row
 * written before this column existed has a null seller and falls back to the platform company at render
 * time, which is byte-identical to what it produced before — the backfill semantics the writer relies on.
 *
 * The posture snapshot it must agree with (`seller_posture`) is a scalar column delivered separately; this
 * is only the concrete party the posture resolves to. Server-only, reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->json('seller')->nullable()->after('seller_posture');
        });
    }

    public function down(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->dropColumn('seller');
        });
    }
};
