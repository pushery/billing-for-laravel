<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a subscription the local engine bills was paused, null while it is not.
 *
 * A pause stops collection and access. When the subscription goes on, the time the pause took is added to the period
 * it is in, so the days the customer had paid for, or would have been billed for, are still there after it. The
 * moment the pause began is what that time is measured from.
 *
 * A DATETIME, as every moment the package keeps in UTC is: a TIMESTAMP passes through the session time zone on MySQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_subscriptions', function (Blueprint $table): void {
            $table->dateTime('paused_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('billing_subscriptions', function (Blueprint $table): void {
            $table->dropColumn('paused_at');
        });
    }
};
