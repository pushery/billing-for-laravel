<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per (effect, reference): did THIS effect already run for THIS thing? It is both the dedup
 * ledger the effects rely on and the record of what still owes work — a failed run is what
 * `billing:webhooks:replay` picks back up.
 *
 * The `reference` is chosen by the EFFECT, not by the transport, and that distinction is load-bearing.
 * Most effects dedup per delivery, so their reference is the provider's event id. But an effect that
 * must fire once per INVOICE (the dunning notice) uses the invoice reference instead — because a
 * provider mints a FRESH event id for every retry of the same failing invoice, so deduping the dunning
 * mail on the event id would send it once per retry.
 *
 * `delivery_id` points back at the webhook delivery whose payload can re-drive this effect, so a replay
 * can rebuild the event a failed run needs. It is nullable: an effect can be driven from somewhere other
 * than a webhook.
 *
 * Index names are given explicitly — the generated names would exceed MySQL's 64-character limit.
 * Server-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_webhook_effect_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('provider');
            $table->string('reference');
            $table->string('effect');
            $table->unsignedBigInteger('delivery_id')->nullable();
            $table->string('status')->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();

            // The dedup identity: one run per effect per reference. This unique is what makes the claim
            // race-safe — two concurrent workers cannot both insert a fresh claim.
            $table->unique(['provider', 'reference', 'effect'], 'billing_effect_runs_unique');
            $table->index(['status'], 'billing_effect_runs_status_idx');
            $table->index(['delivery_id'], 'billing_effect_runs_delivery_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_webhook_effect_runs');
    }
};
