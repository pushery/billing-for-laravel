<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The time of the provider report a merchant account's capabilities were last taken from.
 *
 * Reports arrive out of order, and one retried after a failure can land after a newer one. Without the time
 * of the report the row last took, an older report that the account could still be paid would undo a newer
 * one that it could not. Kept in seconds since the epoch, as the provider stamps its events and as
 * `billing_subscriptions.synced_event_at` keeps them.
 *
 * Server-only, reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_merchant_accounts', function (Blueprint $table): void {
            $table->unsignedBigInteger('capabilities_event_at')->nullable()->after('capabilities_refreshed_at');
        });
    }

    public function down(): void
    {
        Schema::table('billing_merchant_accounts', function (Blueprint $table): void {
            $table->dropColumn('capabilities_event_at');
        });
    }
};
