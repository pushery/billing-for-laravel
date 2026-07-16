<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The one-time add-on purchase ledger. A checkout session's `reference` is unique, so a webhook
 * replay credits an add-on exactly once. The credit EFFECT is project-defined; this table only
 * guarantees the once-per-session record. Server-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_addon_purchases', function (Blueprint $table): void {
            $table->id();
            $table->morphs('owner');
            $table->string('reference')->unique();
            $table->string('addon_key');
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_addon_purchases');
    }
};
