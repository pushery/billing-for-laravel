<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Pushery\Billing\Support\MigrationLocks;

/**
 * The key an erasure leaves on a merchant's creditor account, the same one it leaves on the merchant's documents.
 *
 * An erasure keeps the account and sets its merchant to null, so the account number stays taken and the bookings
 * already made on it keep pointing at it. With the key, a document of the erased merchant still finds that account
 * when a later period books it. Null on the accounts of merchants who are still here, and on accounts erased before
 * the key was recorded. Server-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        MigrationLocks::bound();

        Schema::table('billing_merchant_creditor_accounts', function (Blueprint $table): void {
            $table->char('erased_merchant_key', 32)->nullable()->after('merchant_erased_at');
        });
    }

    public function down(): void
    {
        MigrationLocks::bound();

        Schema::table('billing_merchant_creditor_accounts', function (Blueprint $table): void {
            $table->dropColumn('erased_merchant_key');
        });
    }
};
