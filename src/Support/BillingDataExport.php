<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Pushery\Billing\ValueObjects\MerchantScope;

/**
 * Everything the package holds about one person, as plain data — the answer to a subject-access or
 * data-portability request (GDPR Art. 15 and Art. 20). That is the person as a buyer and, on a marketplace, as a
 * merchant: the same account can do both, and its merchant rows carry no owner columns.
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

        // The same person as a merchant, on the other side of a routed sale: their provider account, payouts,
        // balances, tax standing and the documents about them. A table both sides list holds rows that belong to
        // a buyer, such as the subscription a fan holds with the merchant. The buyer receives those in their own
        // export, and handing them to the merchant would hand over somebody else's data (Art. 15(4)).
        $buyerTables = OwnerScopedTables::ownerAxis()->all();

        foreach ($this->records->export(OwnerScopedTables::merchantAxis(), $owner) as $table => $rows) {
            if (! in_array($table, $buyerTables, true)) {
                $export[$table] = $rows;
            }
        }

        // The coupons the person issued as a merchant. They are scoped by the sentinel string rather than by morph
        // columns, so no axis reaches them, and they are theirs all the same.
        $export['billing_coupons'] = $this->couponsIssuedBy($owner);

        // The audit ledger keys on subject/actor, not owner — but a subject-access request covers the
        // owner's billing history all the same, so include the rows where they are the subject OR the actor.
        $export['billing_events'] = array_values(DB::table('billing_events')
            ->where(fn (Builder $q): Builder => $q
                ->where('subject_type', $owner->getMorphClass())->where('subject_id', $owner->getKey()))
            ->orWhere(fn (Builder $q): Builder => $q
                ->where('actor_type', $owner->getMorphClass())->where('actor_id', $owner->getKey()))
            ->get()
            ->map(fn (object $row): array => $this->isAbout($row, $owner) ? (array) $row : $this->withoutTheOtherParty((array) $row))
            ->all());

        return $export;
    }

    /** Whether an audit row is about the person, rather than one in which they only acted. */
    private function isAbout(object $row, Model $owner): bool
    {
        $key = $owner->getKey();
        $subjectId = $row->subject_id ?? null;

        return ($row->subject_type ?? null) === $owner->getMorphClass()
            && is_scalar($subjectId) && is_scalar($key) && (string) $subjectId === (string) $key;
    }

    /**
     * A row in which the person only acted, without the party it was about.
     *
     * What the person did is theirs: the type, the source and the moment. Whom it was done to and what the row
     * records about them belong to that other party, a customer a support agent refunded, and a copy handed to the
     * agent must not hand those over (Art. 15(4)).
     *
     * @param  array<array-key, mixed>  $row
     * @return array<array-key, mixed>
     */
    private function withoutTheOtherParty(array $row): array
    {
        return array_replace($row, ['subject_type' => null, 'subject_id' => null, 'payload' => null]);
    }

    /**
     * The coupons this person issued as a merchant, none when the model is not saved and so scopes nothing.
     *
     * @return list<array<array-key, mixed>>
     */
    private function couponsIssuedBy(Model $owner): array
    {
        $key = $owner->getKey();

        if (! is_int($key) && ! is_string($key)) {
            return [];
        }

        return array_values(DB::table('billing_coupons')
            ->where('merchant_uid', MerchantScope::forMerchant($owner)->uid())
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->all());
    }
}
