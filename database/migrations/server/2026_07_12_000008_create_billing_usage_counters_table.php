<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The metered-usage counters, one row per owner per METER per period. Tracks used and reserved units
 * so a request can atomically reserve budget before running and commit or release it after. An owner
 * meters more than one thing (emails sent AND contacts stored), so the meter key is part of the
 * identity — without it the two would share a counter and each would enforce the other's limit.
 * Server-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_usage_counters', function (Blueprint $table): void {
            $table->id();
            $table->morphs('owner');
            $table->string('meter_key');
            $table->string('period');
            $table->unsignedBigInteger('used')->default(0);
            $table->unsignedBigInteger('reserved')->default(0);
            $table->timestamps();

            // Named explicitly: the generated name would exceed MySQL's 64-character identifier limit.
            $table->unique(['owner_type', 'owner_id', 'meter_key', 'period'], 'billing_usage_counters_owner_meter_period_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_usage_counters');
    }
};
