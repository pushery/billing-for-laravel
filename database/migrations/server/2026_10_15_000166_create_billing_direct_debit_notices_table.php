<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Pushery\Billing\Support\BillingSchema;

/**
 * Every notice of an upcoming SEPA direct debit the local engine sent, one row per notice.
 *
 * The SEPA core rulebook lets a direct debit be drawn only once the payer was told its amount and its date. The
 * engine asks this table before it charges a mandate that draws direct debits: a debit is drawn when a notice for
 * its amount went out the agreed number of days before. A notice for a subscription announces the amount for
 * each later due date as well (`recurring`), so a subscription whose amount stays the same is announced once; a
 * notice for a charge of its own, such as the rest of a period after a change, names its order and covers that
 * charge alone.
 *
 * The row goes with its owner on an erasure. The moments are DATETIME, as every moment the package keeps in UTC
 * is. Server-only, reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_direct_debit_notices', function (Blueprint $table): void {
            $table->id();
            BillingSchema::morphs($table, 'owner', 'billing_direct_debit_notices_owner_index');
            $table->string('provider');
            $table->string('mandate_reference');
            $table->foreignId('subscription_id')->nullable()->constrained('billing_subscriptions')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('billing_orders')->nullOnDelete();
            $table->bigInteger('amount_minor');
            $table->string('currency', 3);
            $table->date('debit_on');
            $table->boolean('recurring');
            $table->dateTime('announced_at');

            $table->index(['provider', 'mandate_reference'], 'billing_direct_debit_notices_mandate_index');
            // PostgreSQL creates no index for a foreign key, and a notice is looked up by what it announces.
            $table->index('subscription_id', 'billing_direct_debit_notices_subscription_index');
            $table->index('order_id', 'billing_direct_debit_notices_order_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_direct_debit_notices');
    }
};
