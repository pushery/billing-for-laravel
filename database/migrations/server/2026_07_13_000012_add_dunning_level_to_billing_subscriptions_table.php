<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The highest dunning rung an owner has already been notified at, so `billing:dunning:advance` sends
 * each escalating reminder exactly once and never re-sends. It rides on the same delinquency clock:
 * it advances as rungs are reached and resets to 0 the moment the subscription recovers (the plan-sync
 * effect clears it alongside delinquent_since). A separate migration — never edit the create migration,
 * which is published and may already have run. Server-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_subscriptions', function (Blueprint $table): void {
            $table->unsignedInteger('dunning_level')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('billing_subscriptions', function (Blueprint $table): void {
            $table->dropColumn('dunning_level');
        });
    }
};
