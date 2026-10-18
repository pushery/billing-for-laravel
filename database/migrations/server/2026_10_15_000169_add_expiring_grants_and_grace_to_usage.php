<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Pushery\Billing\Support\BillingSchema;

/**
 * Units of a meter given for a limited time, and the grace a blocking meter may run into.
 *
 * `billing_usage_grants` holds one row per grant: what was given, what is left, until when, and what an expiry
 * wrote off. Usage draws on it after the tier's per-cycle allowance and before the bought units, the grant that
 * expires first first, so the draw index leads with the owner and the meter and ends with the expiry. A
 * `reference` makes a grant idempotent per owner and meter, so a renewal that runs twice for one period grants
 * once. The rows go with their owner on an erasure, as the bought units do.
 *
 * `billing_prepaid_units.debt` is the overdraft a meter's grace let an owner run into, owed until the next grant
 * pays it off. `billing_usage_reservations.grace` is the grace a hold was taken with, frozen with it like
 * `included`, so a configuration change between reserving and settling cannot move it.
 *
 * The moments are DATETIME, as every moment the package keeps in UTC is. Server-only, reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_usage_grants', function (Blueprint $table): void {
            $table->id();
            BillingSchema::morphs($table, 'owner', indexed: false);
            $table->string('meter_key');
            $table->unsignedBigInteger('units');
            $table->unsignedBigInteger('remaining');
            $table->string('reference')->nullable();
            $table->dateTime('granted_at');
            $table->dateTime('expires_at');
            $table->dateTime('expired_at')->nullable();
            $table->unsignedBigInteger('expired_units')->nullable();
            $table->timestamps();

            $table->index(['owner_type', 'owner_id', 'meter_key', 'expires_at'], 'billing_usage_grants_draw_index');
            $table->unique(['owner_type', 'owner_id', 'meter_key', 'reference'], 'billing_usage_grants_reference_unique');
            $table->index(['expired_at', 'expires_at'], 'billing_usage_grants_expiry_index');
        });

        Schema::table('billing_prepaid_units', function (Blueprint $table): void {
            $table->unsignedBigInteger('debt')->default(0);
        });

        Schema::table('billing_usage_reservations', function (Blueprint $table): void {
            $table->unsignedInteger('grace')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('billing_usage_reservations', function (Blueprint $table): void {
            $table->dropColumn('grace');
        });

        Schema::table('billing_prepaid_units', function (Blueprint $table): void {
            $table->dropColumn('debt');
        });

        Schema::dropIfExists('billing_usage_grants');
    }
};
