<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Pushery\Billing\Support\MigrationLocks;

/**
 * Whether a document's sale is declared in the import scheme's return (IOSS), frozen on the issued document.
 *
 * The import scheme takes the distance sales of goods imported in consignments of up to 150 euros, which the
 * platform supplies itself where it facilitates them. It is filed monthly and apart from the one-stop-shop
 * return, so the document carries its own marker beside `oss`: a sale belongs in one of the two returns or in
 * neither, and which one cannot be derived again once the classification behind it is gone.
 *
 * Default false, like `oss`: most documents belong in no special return. Server-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        MigrationLocks::bound();

        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->boolean('ioss')->default(false)->after('oss');
        });
    }

    public function down(): void
    {
        MigrationLocks::bound();

        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->dropColumn('ioss');
        });
    }
};
