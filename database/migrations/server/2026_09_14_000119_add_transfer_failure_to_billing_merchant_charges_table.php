<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a merchant's share failed to move after the sale was paid, and what was said when it did.
 *
 * On a separate transfer the share moves in a second call, after the buyer's payment has already gone
 * through. That call can fail on its own: a restricted connected account, a network error, a provider
 * outage. Until now the failure left the row `pending`, exactly like a payment still clearing, and nothing
 * afterwards could tell the two apart, so a sale the buyer paid for could leave its merchant unpaid for good.
 *
 * ## Why two nullable columns rather than a new settlement state
 *
 * The state still answers "has the share settled", and the true answer is no. A third reading of `pending`
 * would ask every existing reader of the column to learn a case, where these columns only add a fact: when
 * it last failed and why. Both stay on the row once the share does move, as the record that it once didn't.
 *
 * Server-only, reversible, additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_merchant_charges', function (Blueprint $table): void {
            $table->timestamp('transfer_failed_at')->nullable()->after('transfer_moved_minor');
            $table->string('transfer_failure')->nullable()->after('transfer_failed_at');
        });
    }

    public function down(): void
    {
        Schema::table('billing_merchant_charges', function (Blueprint $table): void {
            $table->dropColumn(['transfer_failed_at', 'transfer_failure']);
        });
    }
};
