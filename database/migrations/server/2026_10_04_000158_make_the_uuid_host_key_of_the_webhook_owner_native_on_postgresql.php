<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pushery\Billing\Support\BillingSchema;
use Pushery\Billing\Support\TableLookup;

/**
 * The host key of a webhook delivery's owner in PostgreSQL's native uuid type, for payments-only mode.
 *
 * Full mode retypes it in `make_the_uuid_host_keys_native_on_postgresql`, together with the host keys of every other
 * table, and payments-only mode, which has only this one, runs this migration instead. Each finds the column retyped
 * where the other ran, and leaves it. Like that one, it does nothing on another engine or where the host keys its
 * models by integer.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql' || BillingSchema::hostKeyType() !== 'uuid') {
            return;
        }

        if (! Schema::hasColumn(TableLookup::qualified('billing_webhook_events'), 'owner_id')
            || Schema::getColumnType(TableLookup::qualified('billing_webhook_events'), 'owner_id') !== 'bpchar') {
            return;
        }

        $grammar = Schema::getConnection()->getQueryGrammar();

        DB::statement(sprintf(
            'alter table %s alter column %s type uuid using %s::uuid',
            $grammar->wrapTable('billing_webhook_events'),
            $grammar->wrap('owner_id'),
            $grammar->wrap('owner_id'),
        ));
    }

    public function down(): void
    {
        // The type stays. In full mode the migration this one stands in for turns it back on its own rollback, and in
        // payments-only mode rolling back the table's own migration drops the column with the table.
    }
};
