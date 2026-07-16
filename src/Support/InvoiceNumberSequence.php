<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Support\Facades\DB;
use Pushery\Billing\Models\NumberSequence;

/**
 * Hands out gap-free, sequential invoice numbers per scope. The next value is read and advanced
 * inside a transaction under a row lock, so two concurrent invoices never receive the same number
 * and no number is ever skipped — the legal requirement for immutable invoice numbering.
 */
final class InvoiceNumberSequence
{
    public function next(string $scope): int
    {
        return DB::transaction(function () use ($scope): int {
            NumberSequence::query()->insertOrIgnore([
                'scope' => $scope,
                'next_number' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $sequence = NumberSequence::query()->where('scope', $scope)->lockForUpdate()->firstOrFail();
            $number = $sequence->next_number;
            $sequence->update(['next_number' => $number + 1]);

            return $number;
        });
    }

    /** Format a sequence value as a display invoice number, e.g. "2026-000042". */
    public function format(string $scope, int $number): string
    {
        return sprintf('%s-%06d', $scope, $number);
    }
}
