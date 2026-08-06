<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When this merchant's balance went negative and stayed there.
 *
 * Not derivable from anything already on the row. `updated_at` moves every time the balance changes, so it
 * answers "when did this last move", which is the opposite of the question: a debt that has been paid down
 * twice looks NEWER than one nobody has touched, and it is the untouched one that is the receivable.
 *
 * Set when the balance crosses into debt from anywhere else, and cleared the moment it is settled — so a
 * merchant who runs up a second debt starts a second clock rather than inheriting the first one's age.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_merchant_balances', function (Blueprint $table): void {
            $table->timestamp('in_debt_since')->nullable()->after('balance_minor');
        });
    }

    public function down(): void
    {
        Schema::table('billing_merchant_balances', function (Blueprint $table): void {
            $table->dropColumn('in_debt_since');
        });
    }
};
