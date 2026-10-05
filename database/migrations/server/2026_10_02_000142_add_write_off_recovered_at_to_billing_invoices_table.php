<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When a later payment reopened a write-off, so that the next payment of the same amount does not reopen it again.
 *
 * A correction issued because the money would not arrive is reopened when it arrives after all, and nothing
 * recorded that it had been. Every later payment of the same amount in the same currency, which for a subscriber
 * is every following month's payment, matched the same correction and announced the recovery again, and the
 * review list went on listing a write-off that had been reversed.
 *
 * The recoveries announced before this column existed are read back from the audit line written for each of them,
 * `invoice.write_off_recovered` with the correction's id, and stamped with the time of the first. A correction
 * whose audit line is gone stays unstamped, and the next matching payment reopens it once more.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->timestamp('write_off_recovered_at')->nullable();
        });

        DB::table('billing_events')
            ->where('type', 'invoice.write_off_recovered')
            ->orderBy('id')
            ->get(['payload', 'created_at'])
            ->each(static function (stdClass $event): void {
                $payload = is_string($event->payload) ? json_decode($event->payload, true) : null;
                $correction = is_array($payload) && is_int($payload['correction_id'] ?? null) ? $payload['correction_id'] : 0;

                DB::table('billing_invoices')
                    ->where('id', $correction)
                    ->whereNull('write_off_recovered_at')
                    ->update(['write_off_recovered_at' => $event->created_at]);
            });
    }

    public function down(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->dropColumn('write_off_recovered_at');
        });
    }
};
