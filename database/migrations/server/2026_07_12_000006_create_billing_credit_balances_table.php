<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The customer credit-balance ledger, one balance per owner per currency. Credit-balance proration
 * (Mollie/Adyen, which have no provider-side balance) writes unused time here and offsets the next
 * order against it. Server-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_credit_balances', function (Blueprint $table): void {
            $table->id();
            $table->morphs('owner');
            $table->char('currency', 3);
            $table->bigInteger('balance_minor')->default(0);
            $table->timestamps();

            $table->unique(['owner_type', 'owner_id', 'currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_credit_balances');
    }
};
