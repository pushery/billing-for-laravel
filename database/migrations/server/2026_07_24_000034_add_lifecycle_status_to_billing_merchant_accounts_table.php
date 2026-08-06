<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The platform's own position on a merchant, beside the provider's live capability flags.
 *
 * They answer different questions and both are needed. The flags are what the provider currently reports
 * and they move on their own — several times during a single verification. The status is a decision the
 * platform made and holds until it makes another. Deriving one from the other would mean a merchant
 * suspended and reinstated by every intermediate report, each one an event somebody downstream reacts to.
 *
 * Defaults to `active`, which is what every existing row means: nothing had gone wrong with them.
 *
 * Server-only, reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_merchant_accounts', function (Blueprint $table): void {
            $table->string('status')->default('active')->after('account_reference');
            // Why, in the platform's own words. An operator looking at a suspended merchant needs to know
            // whether the provider withdrew something or somebody here made a call.
            $table->string('status_reason')->nullable()->after('status');
            $table->timestamp('status_changed_at')->nullable()->after('status_reason');

            // The routing decision reads status beside the capabilities on every routed payment.
            $table->index(['provider', 'status'], 'billing_merchant_accounts_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('billing_merchant_accounts', function (Blueprint $table): void {
            $table->dropIndex('billing_merchant_accounts_status_index');
            $table->dropColumn(['status', 'status_reason', 'status_changed_at']);
        });
    }
};
