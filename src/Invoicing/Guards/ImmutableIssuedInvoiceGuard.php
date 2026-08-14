<?php

declare(strict_types=1);

namespace Pushery\Billing\Invoicing\Guards;

use Pushery\Billing\Models\InvoiceRecord;
use RuntimeException;

/**
 * An issued invoice does not change. This refuses the update that would change it.
 *
 * ## Why this one takes the RECORD where its siblings take values
 *
 * A deliberate exception, not an oversight. The rule is not about a value but about a DIFFERENCE: what this
 * row holds now against what it held when it was loaded. `isDirty()` and `getRawOriginal()` are the seams
 * that answer it, and reproducing them by handing in two snapshots would move the same comparison outside
 * and give a caller the chance to hand in a pair that agrees.
 *
 * ## Three comparisons, and two of them cannot use `isDirty()`
 *
 * The scalars can: `isDirty()` is a reliable, engine-neutral comparison for them, and the frozen set is
 * `InvoiceRecord::FROZEN_SCALARS` — one list, read here and by everything else that has to know it.
 *
 * `lines` and `seller` are JSON columns, and `isDirty()` compares the ENCODED string. A provider engine
 * re-serializes the same content differently — a MySQL JSON round-trip is not byte-identical to PHP's
 * `json_encode` — so a faithful re-persist of unchanged lines would falsely trip. They are compared DECODED
 * with a loose inequality instead, which catches a real edit while ignoring serialization noise.
 *
 * `buyer` is deliberately NOT here: it stays mutable so a credit note persisted before its original can
 * backfill it. A seller that mirrored buyer would be mutable and would break its own immutability.
 */
final class ImmutableIssuedInvoiceGuard
{
    /** The JSON columns compared by decoded content rather than by their encoded string. */
    public const array FROZEN_JSON = ['lines', 'seller'];

    /** @throws RuntimeException naming the column that tried to change */
    public function assertUnchanged(InvoiceRecord $invoice): void
    {
        foreach (InvoiceRecord::FROZEN_SCALARS as $field) {
            if ($invoice->isDirty($field)) {
                throw $this->refusal($field);
            }
        }

        foreach (self::FROZEN_JSON as $field) {
            $raw = $invoice->getRawOriginal($field);
            $original = is_string($raw) ? json_decode($raw, true) : null;

            if ($original != $invoice->getAttribute($field)) {
                throw $this->refusal($field);
            }
        }
    }

    private function refusal(string $field): RuntimeException
    {
        return new RuntimeException(
            "An issued invoice is immutable; '{$field}' cannot change after it is recorded."
        );
    }
}
