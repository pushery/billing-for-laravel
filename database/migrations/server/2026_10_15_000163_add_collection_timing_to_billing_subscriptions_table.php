<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the local engine collects each period of a subscription: `in_advance` or `in_arrears`.
 *
 * Written when the subscription starts, from `billing.collection_timing`, and never changed afterwards. A
 * subscription started before this column existed holds null, which reads as in arrears: that is how its periods
 * have been collected so far, and switching it would collect the period in progress twice, once when it closes and
 * once as the payment for the next.
 *
 * No index: the engine selects due rows by their schedule and reads the timing off the row it already holds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_subscriptions', function (Blueprint $table): void {
            $table->string('collection_timing')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('billing_subscriptions', function (Blueprint $table): void {
            $table->dropColumn('collection_timing');
        });
    }
};
