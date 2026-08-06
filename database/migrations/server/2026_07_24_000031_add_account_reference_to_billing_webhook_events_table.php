<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which merchant account a delivery is about, and the dedup key that has to include it.
 *
 * A provider's event ids are unique within the account that produced them, not across a platform and all
 * its merchants. Deduplicating on (provider, event_id) alone therefore has a real collision: two genuine
 * events, from two accounts, that happen to share an id — and the second would be swallowed as a
 * redelivery of the first. The event that goes missing is silently gone; nothing retries, because the
 * package believes it already handled it.
 *
 * The column defaults to the EMPTY STRING rather than null, and that is load-bearing. In a unique index
 * every null is distinct from every other, so a nullable account column would stop deduplicating platform
 * deliveries altogether — every redelivery of a platform event would insert a new row and re-run every
 * effect. An empty string is a value, and values collide.
 *
 * Server-only, reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_webhook_events', function (Blueprint $table): void {
            $table->string('account_reference')->default('')->after('provider');
        });

        Schema::table('billing_webhook_events', function (Blueprint $table): void {
            $table->dropUnique(['provider', 'event_id']);
            $table->unique(['provider', 'account_reference', 'event_id'], 'billing_webhook_events_account_event_unique');
        });
    }

    public function down(): void
    {
        Schema::table('billing_webhook_events', function (Blueprint $table): void {
            $table->dropUnique('billing_webhook_events_account_event_unique');
            $table->unique(['provider', 'event_id']);
        });

        Schema::table('billing_webhook_events', function (Blueprint $table): void {
            $table->dropColumn('account_reference');
        });
    }
};
