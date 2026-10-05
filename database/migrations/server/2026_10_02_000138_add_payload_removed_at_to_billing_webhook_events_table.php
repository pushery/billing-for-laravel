<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When a delivery's payload was removed on purpose, so that a redelivery does not put it back.
 *
 * ## The hole this closes
 *
 * A payload is null for three reasons, and only one of them asks for a refill. A delivery recorded before
 * payloads were kept never had one, and its next delivery fills it in so that it can be replayed. An erasure
 * scrubs the payload of the person who asked, and the retention clock prunes old ones. With null as the only
 * signal, a redelivery after an erasure would write the customer's email, name and address back into a row
 * still linked to them, while the erasure receipt goes on saying it was scrubbed.
 *
 * The scrub and the prune now stamp this column, and the ledger refills only a payload that was never removed.
 *
 * ## The rows already scrubbed
 *
 * Stamped here, with the time this migration runs, because the time of their scrub was never recorded. They
 * are the rows that carry an owner and no payload: an owner is attached while a delivery is processed, after
 * it was recorded with its payload, so a row in that state lost its payload afterwards, to an erasure or to
 * the retention clock. A delivery recorded before payloads were kept and never delivered again has no owner,
 * and it keeps its refill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_webhook_events', function (Blueprint $table): void {
            $table->timestamp('payload_removed_at')->nullable()->after('payload');
        });

        DB::table('billing_webhook_events')
            ->whereNull('payload')
            ->whereNotNull('owner_type')
            ->update(['payload_removed_at' => Carbon::now()]);
    }

    public function down(): void
    {
        Schema::table('billing_webhook_events', function (Blueprint $table): void {
            $table->dropColumn('payload_removed_at');
        });
    }
};
