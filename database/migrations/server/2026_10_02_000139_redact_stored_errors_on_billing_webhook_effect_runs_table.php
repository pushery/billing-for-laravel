<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Replaces the error texts failed webhook effects stored before they were redacted.
 *
 * A failed run stored its exception's message, and the message of a failed insert carries the statement's
 * bindings: the buyer's name, email and address on an invoice. The run table holds nobody otherwise, and no
 * erasure and no retention rule reaches it, so those copies would stay for as long as the row does. A run
 * written from now on stores the failure as `RedactedError` describes it.
 *
 * A stored text cannot be redacted after the fact, because nothing in it says which part is data, so every
 * one is replaced. The run itself stays: its status, its attempts and the delivery it can be replayed from.
 * The full message of a failure reached the application's exception handler when it happened.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('billing_webhook_effect_runs')
            ->whereNotNull('last_error')
            ->update(['last_error' => 'removed on upgrade: a stored message could carry the data the failed effect was writing']);
    }

    public function down(): void
    {
        // The texts were removed because they could carry personal data, so there is nothing to put back.
    }
};
