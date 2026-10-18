<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a revoked grant was given back, beside when and why it was revoked.
 *
 * A chargeback the bank takes back returns the money to the merchant, and the access the chargeback ended is given
 * back with it. The grant grants again, and the revocation stays on the row as the record of what happened in
 * between: nothing about a grant is ever deleted. Nullable, because a grant that was never revoked, or one revoked
 * and never given back, has no such moment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_access_grants', function (Blueprint $table): void {
            $table->dateTime('reinstated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('billing_access_grants', function (Blueprint $table): void {
            $table->dropColumn('reinstated_at');
        });
    }
};
