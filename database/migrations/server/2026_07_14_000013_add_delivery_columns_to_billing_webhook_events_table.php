<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Turns the webhook-event row from a bare idempotency marker into a REPLAYABLE delivery record: the raw
 * verified payload is kept, so an effect that failed can be re-run from it later without asking the
 * provider to redeliver. The columns are all nullable or defaulted, so existing rows stay valid.
 *
 * A separate migration — never edit the create migration, which is published and may already have run.
 * Server-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_webhook_events', function (Blueprint $table): void {
            // The verified payload, exactly as the provider sent it. This is what a replay re-maps.
            $table->json('payload')->nullable();
            $table->string('status')->default('pending');
            $table->text('last_error')->nullable();
            $table->timestamp('handled_at')->nullable();

            $table->index(['status', 'created_at'], 'billing_webhook_events_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('billing_webhook_events', function (Blueprint $table): void {
            $table->dropIndex('billing_webhook_events_status_idx');
            $table->dropColumn(['payload', 'status', 'last_error', 'handled_at']);
        });
    }
};
