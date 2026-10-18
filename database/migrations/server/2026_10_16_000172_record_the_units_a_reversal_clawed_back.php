<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many usage units the reversals of an add-on purchase clawed back.
 *
 * A clawback takes only the units the owner has not used, so a share of the grant is not what came back. A chargeback the
 * bank takes back gives back exactly what was taken, and this is where that number stays. Zero for a purchase whose
 * reversals took no units, and for every reversal made before this column, whose units are not known.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_addon_purchases', function (Blueprint $table): void {
            $table->unsignedBigInteger('clawed_back_units')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('billing_addon_purchases', function (Blueprint $table): void {
            $table->dropColumn('clawed_back_units');
        });
    }
};
