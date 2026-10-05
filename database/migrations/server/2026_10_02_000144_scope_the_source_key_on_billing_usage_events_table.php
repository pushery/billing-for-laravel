<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pushery\Billing\Support\UsageRecorder;

/**
 * A usage source key is unique for its owner and its meter, not across the whole table.
 *
 * The key alone carried the unique index, so the same key used for two meters, or by two owners, had every usage
 * after the first dropped as a retry of it. The index moves to `source_scope`, the hash of owner, meter and key
 * that {@see UsageRecorder::sourceScope()} computes, and the rows recorded before are given theirs, so a retry of
 * a usage recorded before this migration is still recognized after it. `source_key` keeps the caller's key as it
 * was given.
 *
 * Rolling back restores the unique index on the key alone, and fails once two owners or meters have used the same
 * key, because that index cannot hold them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_usage_events', function (Blueprint $table): void {
            $table->string('source_scope', 64)->nullable();
        });

        DB::table('billing_usage_events')
            ->whereNotNull('source_key')
            ->chunkById(500, $this->scope(...));

        Schema::table('billing_usage_events', function (Blueprint $table): void {
            $table->dropUnique(['source_key']);
            $table->unique('source_scope');
        });
    }

    public function down(): void
    {
        Schema::table('billing_usage_events', function (Blueprint $table): void {
            $table->dropUnique(['source_scope']);
            $table->dropColumn('source_scope');
            $table->unique('source_key');
        });
    }

    /** @param  Collection<int, stdClass>  $events */
    private function scope(Collection $events): void
    {
        foreach ($events as $event) {
            if (is_string($event->owner_type) && is_string($event->meter_key) && is_string($event->source_key)) {
                DB::table('billing_usage_events')->where('id', $event->id)->update([
                    'source_scope' => UsageRecorder::sourceScope($event->owner_type, $event->owner_id, $event->meter_key, $event->source_key),
                ]);
            }
        }
    }
};
