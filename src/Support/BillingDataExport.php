<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Everything the package holds about one owner, as plain data — the answer to a subject-access or
 * data-portability request (GDPR Art. 15 and Art. 20).
 *
 * It reads the SAME table map the eraser does, so the two cannot drift: a table the export forgets is data
 * a person is entitled to and never receives, and every table added to the package is covered by both or by
 * neither.
 *
 * The webhook deliveries are included with their raw payloads. That is deliberate and slightly
 * uncomfortable: they are the person's data, they are what the package actually stores, and an export that
 * quietly leaves out the biggest file is not an honest one.
 */
final class BillingDataExport
{
    /**
     * @return array<string, list<array<array-key, mixed>>>
     */
    public function for(Model $owner): array
    {
        $export = [];

        foreach (OwnerScopedTables::all() as $table) {
            $rows = DB::table($table)
                ->where('owner_type', $owner->getMorphClass())
                ->where('owner_id', $owner->getKey())
                ->get()
                ->map(fn (object $row): array => (array) $row)
                ->all();

            $export[$table] = array_values($rows);
        }

        // Child tables key on their parent row, not on the owner, so the filter above cannot see them —
        // they are reached by joining through the parent. They are still this person's data (what they
        // subscribe to and are billed for each cycle), and the eraser reads the same map, so a child table
        // cannot end up covered by one side and forgotten by the other.
        foreach (OwnerScopedTables::CASCADED as $table => $link) {
            $export[$table] = array_values(DB::table($table)
                ->whereIn($link['foreign_key'], DB::table($link['parent'])
                    ->where('owner_type', $owner->getMorphClass())
                    ->where('owner_id', $owner->getKey())
                    ->select('id'))
                ->get()
                ->map(fn (object $row): array => (array) $row)
                ->all());
        }

        // The audit ledger keys on subject/actor, not owner — but a subject-access request covers the
        // owner's billing history all the same, so include the rows where they are the subject OR the actor.
        $export['billing_events'] = array_values(DB::table('billing_events')
            ->where(fn (Builder $q): Builder => $q
                ->where('subject_type', $owner->getMorphClass())->where('subject_id', $owner->getKey()))
            ->orWhere(fn (Builder $q): Builder => $q
                ->where('actor_type', $owner->getMorphClass())->where('actor_id', $owner->getKey()))
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->all());

        return $export;
    }
}
