<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the buyer was, as far as tax is concerned, frozen with the rest of the characteristics.
 *
 * It belongs beside them because it OUTRANKS them: a validated business in another country moves the place
 * of supply whatever the product said. Leaving it derivable would let a buyer who later registers, or later
 * lets a registration lapse, change how a sale that already happened should have been treated.
 *
 * Additive and nullable, so an existing invoice is unchanged.
 *
 * Server-only, reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->string('recipient_tax_status')->nullable()->after('rate_matrix_version');
        });
    }

    public function down(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->dropColumn('recipient_tax_status');
        });
    }
};
