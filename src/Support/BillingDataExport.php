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
final readonly class BillingDataExport
{
    /**
     * The shared table machinery is DEFAULTED rather than required: this class is public surface a consumer
     * may legitimately construct with `new`, and it is stateless, so demanding the dependency would break
     * that for no benefit. The container still injects its own instance.
     */
    public function __construct(private SubjectScopedRecords $records = new SubjectScopedRecords) {}

    /**
     * @return array<string, list<array<array-key, mixed>>>
     */
    public function for(Model $owner): array
    {
        // The same axis, the same table work the eraser does — read out instead of deleted. Child tables
        // included: they key on their parent row rather than on the person, so they are reached by joining
        // through the parent, and covering them here but not there (or the reverse) is exactly the drift
        // one shared implementation makes impossible.
        $export = $this->records->export(OwnerScopedTables::ownerAxis(), $owner);

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
