<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The language a document is written in.
 *
 * Set when the document is created, from its owner's preferred language or the application's, and frozen with the
 * amounts. The readable document renders in it from then on, so one issued document reads the same to the
 * customer, to support and to an auditor, whatever language each of them uses the application in. A row from before
 * this column has none and renders in the current language, as it always did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->string('locale', 16)->nullable()->after('currency');
        });
    }

    public function down(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->dropColumn('locale');
        });
    }
};
