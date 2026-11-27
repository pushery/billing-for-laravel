<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Pushery\Billing\Support\BillingSchema;
use Pushery\Billing\Support\TableLookup;

/**
 * The owner of a webhook delivery, for payments-only mode.
 *
 * The payload a delivery stores carries the customer's personal data, and the owner is how an erasure finds the
 * payloads of one customer. Full mode adds the owner in `add_erasure_columns`, together with the columns an invoice
 * and a purchase need to outlive theirs. Payments-only mode has neither of those tables and runs this migration
 * instead of that one. Where both run, that one comes first and this one finds the owner in place.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn(TableLookup::qualified('billing_webhook_events'), 'owner_type')) {
            if (! $this->ownerIsIndexed()) {
                // Columns found here say that ADD COLUMN ran, not that the index after it did. MySQL runs no DDL
                // inside a transaction, so a `migrate` cut off between the two leaves the owner without the index an
                // erasure finds a customer's deliveries through, and skipping it would record that as done.
                throw new RuntimeException('billing_webhook_events carries owner_type and owner_id without the index over both that this migration creates with them, so a previous migrate stopped between the columns and their index. Add the index (an index on billing_webhook_events (owner_type, owner_id)) and run migrate again.');
            }

            return;
        }

        Schema::table('billing_webhook_events', function (Blueprint $table): void {
            BillingSchema::nullableMorphs($table, 'owner');
        });
    }

    public function down(): void
    {
        // The owner stays. Where this migration added it, rolling back the table's own migration drops it with the
        // table. Where `add_erasure_columns` added it before this migration existed, taking it away here would drop
        // the owner of every delivery stored since, and with it what an erasure looks for.
    }

    /**
     * Whether an index of billing_webhook_events leads with owner_type and owner_id, under any name: the one this
     * migration creates with the columns, or a longer one that serves the same lookups.
     */
    private function ownerIsIndexed(): bool
    {
        foreach (Schema::getIndexes(TableLookup::qualified('billing_webhook_events')) as $index) {
            /** @var array{columns: list<string>} $index */
            if (array_slice($index['columns'], 0, 2) === ['owner_type', 'owner_id']) {
                return true;
            }
        }

        return false;
    }
};
