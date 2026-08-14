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
 * The promise is UNIQUENESS, not gap-freedom — and that is deliberately weaker than "no number is ever
 * skipped", because the code cannot honor the stronger claim. A caller's surrounding transaction may roll
 * back after `next()` advanced the counter, and that leaves a gap. What must never happen is a number
 * issued twice or a number changed after the fact, and neither can: the lock serializes issuance, and a
 * drawn number is frozen by the record it lands on. Whether a gap is acceptable is a jurisdiction's rule
 * and lives in its profile; a jurisdiction that forbids gaps must enforce that itself, above this class.
 *
 * It counts and does nothing else — turning a counter value into a document number belongs to
 * {@see DocumentNumberAllocator}, which knows the series and can resolve its configured prefix. A
 * formatting helper used to sit here too, and the split matters more than it looks: that helper produced a
 * shape no real document carries (no prefix, no year, a narrower running part), so anyone who reached for
 * the obvious-looking method on the obvious-looking class would have minted a plausible number outside the
 * configured series rather than getting an error.
 */
final class InvoiceNumberSequence
{
    public function next(string $scope): int
    {
        return DB::transaction(function () use ($scope): int {
            NumberSequence::query()->insertOrIgnore([
                'scope' => $scope,
                'next_number' => 1,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

            $sequence = NumberSequence::query()->where('scope', $scope)->lockForUpdate()->firstOrFail();
            $number = $sequence->next_number;
            $sequence->update(['next_number' => $number + 1]);

            return $number;
        });
    }
}
