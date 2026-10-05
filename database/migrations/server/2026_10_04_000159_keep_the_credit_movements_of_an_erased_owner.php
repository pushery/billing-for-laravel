<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Pushery\Billing\Support\BillingSchema;

/**
 * The credit movements of an erased owner are kept, unlinked, as the booking records they are.
 *
 * A paid top-up books money in transit against the credit liability, and a spend books the liability against the
 * customer account. The booking batch of a period reads those movements from this table, and an erasure deleted them:
 * a batch built after an owner was erased in its period was short by every movement of theirs. They are kept now the
 * way an invoice is. The owner link becomes optional, `owner_erased_at` records when it was cut, and `billing:prune`
 * removes the rows once the window for erased financial records has passed.
 *
 * `erased_owner_key` keeps the movements of one erased owner together without naming them. The batch books a spend
 * by the share of the balance it consumed, replayed over that owner's movements in order; without a key the movements
 * of every erased owner would replay as one balance.
 *
 * The rollback drops both columns and leaves the owner columns nullable, as the migration that let invoices outlive an
 * erasure does: once an owner has been erased, a kept movement carries none.
 *
 * Server-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_credit_ledger_entries', function (Blueprint $table): void {
            // Through the helpers, so the columns keep the type the host key setting gave them.
            BillingSchema::hostType($table, 'owner_type')->nullable()->change();
            BillingSchema::hostKey($table, 'owner_id')->nullable()->change();
            $table->dateTime('owner_erased_at')->nullable()->index();
            $table->char('erased_owner_key', 32)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('billing_credit_ledger_entries', function (Blueprint $table): void {
            // Each index goes before the column it covers: SQLite refuses to drop an indexed column.
            $table->dropIndex(['erased_owner_key']);
            $table->dropColumn('erased_owner_key');
            $table->dropIndex(['owner_erased_at']);
            $table->dropColumn('owner_erased_at');
        });
    }
};
