<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Pushery\Billing\Marketplace\DocumentNumberAllocator;
use Pushery\Billing\Models\NumberSequence;

/**
 * Hands out unique, monotonically increasing numbers per scope. The next value is read and advanced inside
 * a transaction under a row lock, so two concurrent callers never receive the same number and the counter
 * only ever moves forward.
 *
 * The promise is UNIQUENESS, and gap-freedom only where the caller keeps it. The counter moves inside the
 * caller's transaction on the same connection, so a number drawn in a transaction that rolls back goes back
 * with it, and a caller that draws its number in the transaction that writes its document leaves no gap when
 * that write fails. A number drawn outside such a transaction, or committed before its document fails, stays
 * drawn, and nothing here can tell. What must never happen is a number issued twice or a number changed after
 * the fact, and neither can: the lock serializes issuance, and a drawn number is frozen by the record it lands
 * on. Whether a gap is acceptable is a jurisdiction's rule and lives in its profile; a jurisdiction that
 * forbids gaps must enforce that itself, above this class.
 *
 * It counts and does nothing else — turning a counter value into a document number belongs to
 * {@see DocumentNumberAllocator}, which knows the series and can resolve its configured prefix. The split
 * matters more than it looks: a formatting helper here could not know the series, so it would produce a
 * shape no real document carries (no prefix, no year, a narrower running part), and anyone who reached for
 * the obvious-looking method on the obvious-looking class would mint a plausible number outside the
 * configured series rather than getting an error.
 */
final class InvoiceNumberSequence
{
    public function next(string $scope): int
    {
        return DB::transaction(function () use ($scope): int {
            $row = NumberSequence::model()::query()->where('scope', $scope);

            $sequence = LockedRow::take($row, [
                'scope' => $scope,
                'next_number' => 1,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
            $number = $sequence->next_number;
            $sequence->update(['next_number' => $number + 1]);

            return $number;
        }, LockedRow::ATTEMPTS);
    }
}
