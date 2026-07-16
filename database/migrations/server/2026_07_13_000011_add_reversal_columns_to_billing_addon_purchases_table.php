<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reversal columns for the one-time add-on ledger: the provider PAYMENT reference (a PaymentIntent —
 * distinct from the checkout-session `reference` that is the credit dedup key) so a refund webhook can
 * find the purchase it undoes, and the cumulative reversed amount so two partial refunds each claw back
 * only their delta. A separate migration (never edit the create migration — it is published, and a
 * consuming app may already have run it). Server-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_addon_purchases', function (Blueprint $table): void {
            $table->string('payment_reference')->nullable()->index();
            $table->unsignedBigInteger('reversed_minor')->default(0);
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('billing_addon_purchases', function (Blueprint $table): void {
            // Drop the index before the column it covers (SQLite refuses to drop an indexed column).
            $table->dropIndex(['payment_reference']);
            $table->dropColumn(['payment_reference', 'reversed_minor', 'revoked_at', 'revoked_reason']);
        });
    }
};
