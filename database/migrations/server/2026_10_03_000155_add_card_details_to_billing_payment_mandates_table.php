<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The card behind a card mandate: its brand, the last four digits and its expiry.
 *
 * A local-engine driver keeps its mandates here instead of reading them back from the provider, and a stored
 * method without its expiry is one the expiring-card warning can never warn about. All four stay null for a
 * mandate that is not a card's, and for one stored before the provider's answer was kept.
 *
 * Server-only, reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_payment_mandates', function (Blueprint $table): void {
            $table->string('card_brand')->nullable()->after('method');
            $table->string('card_last4', 4)->nullable()->after('card_brand');
            $table->unsignedTinyInteger('card_exp_month')->nullable()->after('card_last4');
            $table->unsignedSmallInteger('card_exp_year')->nullable()->after('card_exp_month');
        });
    }

    public function down(): void
    {
        Schema::table('billing_payment_mandates', function (Blueprint $table): void {
            $table->dropColumn(['card_brand', 'card_last4', 'card_exp_month', 'card_exp_year']);
        });
    }
};
