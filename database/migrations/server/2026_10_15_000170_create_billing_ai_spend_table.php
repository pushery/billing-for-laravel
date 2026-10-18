<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Pushery\Billing\Support\BillingSchema;

/**
 * What model calls cost per provider and day, and per owner and day: the accounts of the AI spend brake.
 *
 * One row per account, day and currency, keyed by `account`: `provider:<name>` for a provider, `owner:<morph>` for an
 * owner, whose morph columns are filled for that kind alone so an erasure finds them. `spent_micros` is the day's
 * spend in millionths of the currency, raised by one conditional statement, so two calls at the cap cannot both pass
 * it. An owner's rows go with the owner on an erasure; a provider's rows name nobody.
 *
 * Server-only, reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_ai_spend', function (Blueprint $table): void {
            $table->id();
            $table->string('account');
            BillingSchema::nullableMorphs($table, 'owner', 'billing_ai_spend_owner_index');
            $table->date('day');
            $table->string('currency', 3);
            $table->unsignedBigInteger('spent_micros')->default(0);
            $table->timestamps();

            $table->unique(['account', 'day', 'currency'], 'billing_ai_spend_account_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_ai_spend');
    }
};
