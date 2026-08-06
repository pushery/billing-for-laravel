<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The margin a margin-taxed sale was taxed on, frozen at the sale.
 *
 * Without it a refund has nothing to correct against. The margin is the sale price less what the seller paid
 * for the goods, and what they paid is a fact about a purchase that may have happened years earlier, from a
 * record this system never held. Re-deriving it at refund time is not possible; approximating it produces a
 * correction that is wrong in a direction nobody can check.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->bigInteger('margin_minor')->nullable()->after('taxation_basis');
        });
    }

    public function down(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->dropColumn('margin_minor');
        });
    }
};
