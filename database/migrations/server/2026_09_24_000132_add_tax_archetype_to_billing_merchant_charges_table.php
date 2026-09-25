<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What kind of thing a routed sale sold, frozen onto the sale.
 *
 * The amounts say what moved and the purpose says through which lane, but nothing said WHAT was sold. Under
 * intermediation that is the only record of the sale this package writes: no buyer document is issued, so a
 * count of a seller's sales of goods had nothing to read. Written by the lanes that know it at the moment of
 * sale, and deliberately not backfilled: an older row carries no archetype because nothing recorded one, and
 * guessing it now would count sales nobody can check.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_merchant_charges', function (Blueprint $table): void {
            $table->string('tax_archetype')->nullable()->after('purpose');
        });
    }

    public function down(): void
    {
        Schema::table('billing_merchant_charges', function (Blueprint $table): void {
            $table->dropColumn('tax_archetype');
        });
    }
};
