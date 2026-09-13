<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The declarations key, carried from the start of a subscription to the row its mandate webhook writes.
 *
 * On a driver the package bills itself the subscription becomes real only when the verification payment's
 * mandate lands, long after the buyer made their declarations. The intent is what survives in between, the
 * same way it carries the coupon code, so the key has to ride on it or the subscription row can never hold it.
 *
 * Nullable for the reason the subscription column gives: most subscriptions are started without declarations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_subscription_intents', function (Blueprint $table): void {
            $table->string('declaration_reference')->nullable()->after('payment_reference');
        });
    }

    public function down(): void
    {
        Schema::table('billing_subscription_intents', function (Blueprint $table): void {
            $table->dropColumn('declaration_reference');
        });
    }
};
