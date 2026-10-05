<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a sale's merchant share was last asked of the provider.
 *
 * A run that stops between asking and hearing back, a killed worker or a deploy in the middle of a release,
 * leaves the sale pending with neither a failure nor a settlement on it. The time of the request is what lets
 * `billing:doctor` count such a sale and the retry move it, once it is too old to be a transfer still on its
 * way. The retry moves it under the sale's own key, so a transfer that did arrive is not paid twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_merchant_charges', function (Blueprint $table): void {
            $table->timestamp('transfer_requested_at')->nullable()->after('transfer_moved_minor');
        });
    }

    public function down(): void
    {
        Schema::table('billing_merchant_charges', function (Blueprint $table): void {
            $table->dropColumn('transfer_requested_at');
        });
    }
};
