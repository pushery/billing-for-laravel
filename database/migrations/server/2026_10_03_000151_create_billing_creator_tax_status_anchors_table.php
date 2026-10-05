<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Pushery\Billing\Support\BillingSchema;

/**
 * One row per merchant whose tax standing was ever recorded, for the recordings to lock.
 *
 * Two recordings for one creator take turns, so that the second closes the interval the first opened instead
 * of opening one beside it. They used to take turns on the creator's status rows, which leaves a creator's
 * very first recording nothing to lock: two first recordings at once, with different start dates, each opened
 * an interval. This row exists before the first status does, because the recording creates it, so every
 * recording has the same thing to wait on. It holds nothing else, and goes with the merchant.
 *
 * Server-only, reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_creator_tax_status_anchors', function (Blueprint $table): void {
            $table->id();
            BillingSchema::hostType($table, 'merchant_type');
            BillingSchema::hostKey($table, 'merchant_id');

            $table->unique(['merchant_type', 'merchant_id'], 'billing_creator_tax_status_anchors_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_creator_tax_status_anchors');
    }
};
