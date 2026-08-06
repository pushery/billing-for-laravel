<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a merchant disconnected their account from the platform — a different fact from losing a capability.
 *
 * A merchant who fails re-verification can still be reached: transfers stop, but a reversal of money
 * already sent still works. A merchant who deauthorized cannot be reached at all, in either direction, and
 * that is exactly the state in which a clawback becomes impossible. Folding it into the capability flags
 * would leave a platform owed money by that merchant unable to tell the two apart — and the second case is
 * the one somebody has to act on.
 *
 * Server-only, reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_merchant_accounts', function (Blueprint $table): void {
            $table->timestamp('deauthorized_at')->nullable()->after('details_submitted');
        });
    }

    public function down(): void
    {
        Schema::table('billing_merchant_accounts', function (Blueprint $table): void {
            $table->dropColumn('deauthorized_at');
        });
    }
};
