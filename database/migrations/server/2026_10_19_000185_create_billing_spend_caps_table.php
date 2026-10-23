<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Pushery\Billing\Support\BillingSchema;

/**
 * The spend caps: what an owner's usage past the included allowance may cost per billing period.
 *
 * One row per owner, which every metered request of the owner locks while it is measured against the cap, so two
 * requests on two meters take turns. `amount_minor` and `currency` hold the cap the owner set, both null for the
 * configured default. `reached_period` names the period in which the cap last stopped the owner, so the event that
 * announces it fires once per period. An owner's row goes with the owner on an erasure.
 *
 * Server-only, reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_spend_caps', function (Blueprint $table): void {
            $table->id();
            BillingSchema::morphs($table, 'owner', indexed: false);
            $table->unsignedBigInteger('amount_minor')->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('reached_period')->nullable();
            $table->timestamps();

            $table->unique(['owner_type', 'owner_id'], 'billing_spend_caps_owner_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_spend_caps');
    }
};
