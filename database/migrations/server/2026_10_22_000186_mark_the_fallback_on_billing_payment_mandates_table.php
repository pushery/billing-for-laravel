<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Pushery\Billing\Support\MigrationLocks;

/**
 * Which stored mandate an owner keeps as the fallback: the one a cycle is charged to once when the default could not
 * pay, before dunning begins. At most one per owner and provider, never the default, and false on every mandate
 * stored before. Server-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        MigrationLocks::bound();

        Schema::table('billing_payment_mandates', function (Blueprint $table): void {
            $table->boolean('is_fallback')->default(false)->after('is_default');
        });
    }

    public function down(): void
    {
        MigrationLocks::bound();

        Schema::table('billing_payment_mandates', function (Blueprint $table): void {
            $table->dropColumn('is_fallback');
        });
    }
};
