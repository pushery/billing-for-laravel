<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Pushery\Billing\Support\BillingSchema;

/**
 * One row per owner and provider whose mandate was ever stored, for the storing to lock.
 *
 * Two mandates stored for one owner at once take turns, so that only the first becomes the default. They decided
 * that by asking whether the owner already had one, before anything was locked, which leaves an owner's very
 * first mandates nothing to lock: two at once each found no default and each claimed it. This row exists before
 * the first mandate does, because the storing creates it, so every storing has the same thing to wait on. It holds
 * nothing else, and goes with the owner.
 *
 * Server-only, reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_payment_mandate_anchors', function (Blueprint $table): void {
            $table->id();
            BillingSchema::hostType($table, 'owner_type');
            BillingSchema::hostKey($table, 'owner_id');
            $table->string('provider');

            $table->unique(['owner_type', 'owner_id', 'provider'], 'billing_payment_mandate_anchors_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_payment_mandate_anchors');
    }
};
