<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Pushery\Billing\Support\BillingSchema;

/**
 * The one-time purchases an app opened at Shopify.
 *
 * A Shopify purchase carries nothing of the app's but its name, so this row is how the webhook that reports it
 * finds the owner, the add-on, the amount and the declarations again. `purchase_id` is the id Shopify gave the
 * purchase, unique. The row holds no money and no document: Shopify charges the merchant, and the purchase is
 * recorded as an add-on purchase once Shopify reports it active. An owner's rows go with the owner on an erasure.
 *
 * Server-only, reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_shopify_purchases', function (Blueprint $table): void {
            $table->id();
            BillingSchema::morphs($table, 'owner');
            $table->string('purchase_id')->unique();
            $table->string('addon_key');
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3);
            $table->string('declaration_reference')->nullable();
            $table->string('caller_reference')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_shopify_purchases');
    }
};
