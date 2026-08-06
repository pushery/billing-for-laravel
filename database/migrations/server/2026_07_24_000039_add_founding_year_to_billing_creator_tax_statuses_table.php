<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The year the merchant's business started, as declared.
 *
 * It sits on the declaration rather than on the merchant, because it is part of what was DECLARED and a
 * later declaration re-states it. Kept beside the standing it was declared with, it can be read back
 * exactly as it stood when a document was produced — which is the same reason the standing itself is a
 * series rather than a column.
 *
 * It is never derived from when the merchant signed up here. The two are different facts, they differ
 * routinely, and a derivation would be wrong invisibly: nothing about a threshold computed from the wrong
 * starting year looks wrong.
 *
 * Server-only, reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_creator_tax_statuses', function (Blueprint $table): void {
            $table->unsignedSmallInteger('business_founded_year')->nullable()->after('evidence_ref');
        });
    }

    public function down(): void
    {
        Schema::table('billing_creator_tax_statuses', function (Blueprint $table): void {
            $table->dropColumn('business_founded_year');
        });
    }
};
