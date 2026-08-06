<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The subdivision the place evidence settled on, where the resolved country has subdivisions in scope.
 *
 * It is added rather than derived because it CANNOT be derived later. The evidence is written once at the
 * sale and kept for years, and the raw IP that could name a state is deliberately discarded — so a
 * subdivision not captured at the sale is gone for good, and a US nexus counter built afterwards could only
 * ever fill an `unknown` bucket while looking as though it worked.
 *
 * Nullable and narrow on purpose: three characters holds every ISO 3166-2 suffix, the column is written only
 * where the resolved country is in scope (the shipped scope is the US alone), and a consumer who supplies no
 * subdivision signal writes nothing at all. The package cannot invent one — it has no finer input than the
 * country and is not given one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_place_evidence', function (Blueprint $table): void {
            $table->string('resolved_subdivision', 3)->nullable()->after('resolved_country');

            // The counter this exists for asks "how much GMV in this state, in this window" — country and
            // subdivision together, over time. The country index alone cannot answer it without a scan.
            $table->index(
                ['resolved_country', 'resolved_subdivision', 'resolved_at'],
                'billing_place_evidence_subdivision_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('billing_place_evidence', function (Blueprint $table): void {
            $table->dropIndex('billing_place_evidence_subdivision_index');
            $table->dropColumn('resolved_subdivision');
        });
    }
};
