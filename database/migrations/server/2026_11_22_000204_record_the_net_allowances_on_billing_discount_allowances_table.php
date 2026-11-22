<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Pushery\Billing\Support\MigrationLocks;

/**
 * Whether a discount allowance is meant before tax: each cycle then takes its amount off the net, at the rate the cycle
 * is taxed at, and what is left of it stays net. Every allowance granted before keeps false and stays an amount off the
 * gross. Server-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        MigrationLocks::bound();

        Schema::table('billing_discount_allowances', function (Blueprint $table): void {
            $table->boolean('net')->default(false)->after('amount_minor');
        });
    }

    public function down(): void
    {
        MigrationLocks::bound();

        Schema::table('billing_discount_allowances', function (Blueprint $table): void {
            $table->dropColumn('net');
        });
    }
};
