<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When each market was opened or closed, and by whom.
 *
 * Configuration says which countries are open TODAY. It cannot say since when, and "since when" is the
 * question that matters afterwards: a return covers a period, and whether a country belonged in it depends
 * on when it was opened — not on what the file says at the time somebody asks.
 *
 * Append-only. A market that was open for three months and then closed leaves two rows, not an edited one:
 * the sales made in those three months are real and need the record that explains them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_market_access_log', function (Blueprint $table): void {
            $table->id();
            $table->string('country', 2);
            $table->string('state', 16);
            $table->string('actor')->nullable();
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->index(['country', 'recorded_at'], 'billing_market_access_log_country_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_market_access_log');
    }
};
