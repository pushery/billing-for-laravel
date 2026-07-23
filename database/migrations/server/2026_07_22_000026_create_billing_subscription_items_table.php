<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The provider-neutral lines of a local subscription: what is billed each cycle, and for how much.
 *
 * Only Stripe prices usage remotely, so for that driver Cashier's own `subscription_items` stays
 * authoritative and this table simply mirrors nothing. The local engine has no
 * provider-side line model at all — it has to know the cycle's composition itself, which is what these
 * rows carry: the catalog key, an optional provider price reference, a quantity, whether the line is
 * metered, and the amount once it is known.
 *
 * `preprocessor` is the hook for app-priced usage: a metered line's amount is not knowable at
 * subscription time, so the application names a resolver here that computes it when the cycle closes.
 *
 * Server-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_subscription_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('billing_subscription_id')->constrained('billing_subscriptions')->cascadeOnDelete();
            // The catalog key (what the package calls this line) and, when the provider has one, its own
            // price identifier. The reference is nullable because a locally-priced line has none.
            $table->string('plan_key');
            $table->string('price_ref')->nullable();
            $table->unsignedInteger('quantity')->nullable();
            $table->boolean('metered')->default(false);
            // Minor units, matching every other money column in the schema. Nullable because a metered
            // line has no amount until its cycle is priced.
            $table->bigInteger('amount_minor')->nullable();
            $table->char('currency', 3);
            // The application-side resolver that prices this line for the cycle (metered lines only).
            $table->string('preprocessor')->nullable();
            $table->timestamps();

            // One line per catalog key per subscription. This is a dedup, not just a lookup index: the
            // local engine rebuilds a cycle's lines from the catalog, and without it a re-run would
            // append a second copy of every line and double the cycle's amount. It also serves as the
            // foreign key's index — Postgres, unlike MySQL, does not create one for a FK by itself, so
            // an unindexed referencing column would make every cascade delete a sequential scan.
            //
            // The name is given explicitly because the generated one
            // (`billing_subscription_items_billing_subscription_id_plan_key_unique`) is 65 characters and
            // MySQL rejects any identifier over 64 — an error Postgres never raises, since it silently
            // truncates instead. Left to the default, this table simply could not be created on MySQL.
            $table->unique(['billing_subscription_id', 'plan_key'], 'billing_sub_items_subscription_plan_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_subscription_items');
    }
};
