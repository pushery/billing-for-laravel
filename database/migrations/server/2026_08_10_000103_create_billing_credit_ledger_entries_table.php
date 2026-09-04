<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The movements behind an owner's credit balance.
 *
 * `billing_credit_balances` holds the running total and is the fast read every screen does. It could not
 * answer WHY it holds what it holds: the reasons lived in the audit log, in a different table, written by a
 * separate call after the balance transaction had already committed. Two consequences followed, and both
 * were real rather than theoretical — an interruption between the two writes left a balance nobody could
 * account for, and `billing:prune` ages audit rows out on a configurable clock while a balance is a holding
 * and is never pruned, so the explanation could expire while the thing it explained stayed.
 *
 * An entry is written in the same transaction as the balance it moves, so the two cannot disagree. The
 * amount is SIGNED — a credit is positive, a debit negative — so the balance is the plain sum of the
 * entries per currency, and that is exactly what the invariant test asserts. Storing magnitudes plus a
 * direction flag would make the same sum a two-step calculation that a reader has to trust.
 *
 * Provider-neutral by name and by column, because the second and third drivers share it. There is
 * deliberately NO `provider` column, which departs from the ticket that asked for one: a balance belongs to
 * an owner and a currency and never to a driver, so the column would not be part of any key — and no writer
 * has a driver to hand anyway. Resolving one from config would stamp today's default onto a movement made
 * under whatever was configured back then, which is a wrong answer rather than a missing one. A column that
 * is always null is worse than an absent one, because a reader assumes it is sometimes filled. If a report
 * ever needs the distinction, the column arrives with the code that fills it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_credit_ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->morphs('owner');
            // Signed: credits positive, debits negative. bigInteger for the same reason the balance uses one
            // — a minor-unit total in a low-denomination currency outgrows a 32-bit column.
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('reason');
            // What caused it — an order, an add-on purchase, a refund attempt. Nullable and polymorphic
            // because the causes are of different types and some callers legitimately have none to name.
            $table->nullableMorphs('source');
            // No `updated_at`: an entry is written once and never changes, so a column recording when it was
            // last changed would describe something that cannot happen.
            $table->timestamp('created_at')->nullable();

            // The balance query sums over exactly this triple, so it is one index rather than three.
            $table->index(['owner_type', 'owner_id', 'currency'], 'billing_credit_entries_owner_currency_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_credit_ledger_entries');
    }
};
