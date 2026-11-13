<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Pushery\Billing\Support\BillingSchema;

/**
 * A fixed discount the application granted an owner, which the owner's next cycles use up before tax.
 *
 * One row per grant, never updated. `amount_minor` is what was granted in `currency`, and what is left of it is derived
 * from the uses in `billing_discount_allowance_uses`, so the remainder cannot drift from the cycles that consumed it.
 * `cause` names why it was granted, unique per owner, so granting for the same reason twice grants once. `description`
 * is the line the invoice of a cycle shows for what the grant took off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_discount_allowances', function (Blueprint $table): void {
            $table->id();
            // No index on the pair alone: the unique index on owner and cause leads with it and answers every lookup by
            // owner.
            BillingSchema::morphs($table, 'owner', indexed: false);
            $table->string('currency', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->string('cause');
            $table->string('description');
            // With their zone on PostgreSQL and as a DATETIME on MySQL, like every moment of the package's tables.
            $table->dateTimeTz('created_at')->nullable();
            $table->dateTimeTz('updated_at')->nullable();

            $table->unique(['owner_type', 'owner_id', 'cause'], 'billing_discount_allowances_cause_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_discount_allowances');
    }
};
