<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Pushery\Billing\Support\MigrationLocks;

/**
 * Where a cycle stands with the owner's fallback mandate, and which mandate that is: `fallback_attempt` is `due` once
 * the default could not pay, `made` once the fallback was charged and `refused` when it could not pay either, and
 * `fallback_mandate_reference` names the mandate the cycle was handed to. Both stay null on an order that never
 * needed the fallback, so every order stored before stays what it was. Server-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        MigrationLocks::bound();

        Schema::table('billing_orders', function (Blueprint $table): void {
            $table->string('fallback_attempt', 16)->nullable()->after('payment_reference');
            $table->string('fallback_mandate_reference')->nullable()->after('fallback_attempt');
        });
    }

    public function down(): void
    {
        MigrationLocks::bound();

        Schema::table('billing_orders', function (Blueprint $table): void {
            $table->dropColumn(['fallback_attempt', 'fallback_mandate_reference']);
        });
    }
};
