<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Pushery\Billing\Support\BillingSchema;

/**
 * A signal that contradicts what a seller outside the Union declared, with the moment it was observed.
 *
 * Relying on a seller's declaration protects the platform only for as long as it does not know better (Art. 5c
 * Implementing Regulation 282/2011). Once something it holds contradicts the declaration, such as a delivery address of
 * the seller in the Union, the next sale of that seller waits until the seller has declared again, and this row is the
 * record of when the platform learned it. It is written once.
 *
 * Kept and unlinked on an erasure for the reason its declarations are, and on the same clock.
 *
 * The moments are DATETIME, as every moment the package keeps in UTC is. Server-only, reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_seller_declaration_contradictions', function (Blueprint $table): void {
            $table->id();
            BillingSchema::nullableMorphs($table, 'merchant', 'billing_seller_declaration_contradictions_merchant_index');
            $table->string('signal', 64);
            $table->text('detail')->nullable();
            $table->dateTime('observed_at');
            $table->dateTime('merchant_erased_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_seller_declaration_contradictions');
    }
};
