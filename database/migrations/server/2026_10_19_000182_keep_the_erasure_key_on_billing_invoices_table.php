<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Pushery\Billing\Support\MigrationLocks;

/**
 * The key an erasure leaves on a document it keeps, drawn at random for the erased person and the same on every row
 * of theirs that keeps one.
 *
 * An erasure keeps an invoice and sets its owner to null. A settlement document of a merchant is then still a booking
 * on the merchant's payable, and the export books it to the creditor account the merchant had, which keeps the same
 * key on the merchant axis. Null on documents of a person who is still here, and on documents erased before the key
 * was recorded. Server-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        MigrationLocks::bound();

        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->char('erased_owner_key', 32)->nullable()->after('owner_erased_at');
        });
    }

    public function down(): void
    {
        MigrationLocks::bound();

        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->dropColumn('erased_owner_key');
        });
    }
};
