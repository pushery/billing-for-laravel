<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What an erasure request needs in order to be honest.
 *
 * (1) A webhook delivery gets an OWNER. The raw payload the receiver stores carries the customer's personal
 * data — email, name, billing address, card last four — and until now that table had no owner column at
 * all, so it was simultaneously the package's largest store of personal data and the one an erasure could
 * not even find. With an owner on it, an erasure can scrub exactly that customer's payloads.
 *
 * (2) An invoice and a purchase get `owner_erased_at`. They are NOT deleted with the owner: a valid invoice
 * must carry the buyer's name and address (§14 UStG), and invoices must be kept for years (§147 AO, §14b
 * UStG) — a right to erasure yields to a legal retention obligation (GDPR Art. 17(3)(b)). So they are
 * unlinked from the owner and kept, and the retention clock (billing:prune) removes them when the law no
 * longer requires them. Cascading them away would turn a compliance gap into a compliance disaster.
 *
 * Server-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_webhook_events', function (Blueprint $table): void {
            $table->nullableMorphs('owner');
        });

        foreach (['billing_invoices', 'billing_addon_purchases'] as $retained) {
            Schema::table($retained, function (Blueprint $table): void {
                $table->timestamp('owner_erased_at')->nullable()->index();

                // The owner link has to become optional, or the row cannot outlive the owner — and these
                // rows MUST outlive them: the law requires the invoice to be kept, and to keep carrying the
                // buyer's name and address while it is.
                $table->string('owner_type')->nullable()->change();
                $table->unsignedBigInteger('owner_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        Schema::table('billing_webhook_events', function (Blueprint $table): void {
            $table->dropMorphs('owner');
        });

        // Deliberately asymmetric: this drops owner_erased_at but does NOT restore owner_type/owner_id to
        // NOT NULL. Once an erasure has run, a retained invoice legitimately carries a null owner_id, so
        // re-imposing NOT NULL on rollback would fatal on exactly the rows this migration exists to keep.
        // A rollback therefore leaves the owner columns nullable; up() is idempotent (a repeated
        // nullable()->change()), so re-migrating converges. The financial data is never at risk either way.
        foreach (['billing_invoices', 'billing_addon_purchases'] as $retained) {
            Schema::table($retained, function (Blueprint $table): void {
                // Drop the index before the column it covers (SQLite refuses to drop an indexed column).
                $table->dropIndex(['owner_erased_at']);
                $table->dropColumn('owner_erased_at');
            });
        }
    }
};
