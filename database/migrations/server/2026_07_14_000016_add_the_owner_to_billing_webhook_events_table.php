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
};
