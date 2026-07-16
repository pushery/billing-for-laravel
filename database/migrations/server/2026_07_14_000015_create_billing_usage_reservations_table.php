<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per HELD unit of allowance: the claim a request makes on a metered ceiling before it does the
 * work it will be billed for. The counter's `reserved` column stays the fast aggregate the ceiling check
 * reads under its row lock; this table is what gives each hold an identity, so a hold can be settled
 * exactly once and — the part that matters — EXPIRE.
 *
 * Without an expiry, a worker that dies between reserving and settling holds allowance for the rest of
 * the billing period, and a paying customer is refused a request they never spent. A leaked hold is the
 * feature's own outage vector, so every hold carries the moment it stops counting.
 *
 * Server-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_usage_reservations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('token')->unique();
            $table->morphs('owner');
            $table->string('meter_key');
            $table->string('period');
            $table->unsignedBigInteger('amount');
            $table->string('state')->default('pending');
            $table->timestamp('expires_at');
            $table->timestamps();

            // The sweep's index, and the only one the package queries this table by (a hold is found by its
            // token, which the unique index above already covers). Named explicitly: the generated name
            // would exceed MySQL's 64-character identifier limit.
            $table->index(['state', 'expires_at'], 'billing_usage_reservations_expiry_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_usage_reservations');
    }
};
