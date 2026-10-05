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
 * `lines` and `seller` are JSON columns cast to arrays, and `isDirty()` compares the decoded arrays with
 * `===`, which is strict about key order and about `19` against `19.0`. A provider engine re-serializes the
 * same content in its own way — a MySQL JSON round-trip reorders keys and is not byte-identical to PHP's
 * `json_encode` — so a faithful re-persist of unchanged lines would falsely trip. They are compared decoded
 * with a loose inequality instead, which catches a real edit while ignoring that noise.
 *
 * `buyer` is frozen as well, compared the same way, from the moment it names somebody. The recipient's name,
 * address and VAT id are mandatory on the document, and a reverse-charge invoice rests on that VAT id; an
 * issued document is corrected by a document of its own that refers to it, never by rewriting it. A buyer
 * that is still EMPTY may be filled once: a credit note persisted before the invoice it corrects carries no
 * buyer of its own, and takes the invoice's when that arrives.
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
            if ($this->storedJson($invoice, $field) != $invoice->getAttribute($field)) {
                throw $this->refusal($field);
            }
        }

        $buyer = $this->storedJson($invoice, 'buyer');

        // Loose on purpose, like the comparison above: an empty buyer may be filled, and the same buyer in another
        // key order is no change.
        if (! in_array($buyer, [null, [], $invoice->getAttribute('buyer')])) {
            throw $this->refusal('buyer');
        }
    }

    /** A JSON column as it was loaded, decoded, or null where it held nothing. */
    private function storedJson(InvoiceRecord $invoice, string $field): mixed
    {
        $raw = $invoice->getRawOriginal($field);

        return is_string($raw) ? json_decode($raw, true) : null;
    }

    private function refusal(string $field): RuntimeException
    {
        return new RuntimeException(
            "An issued invoice is immutable; '{$field}' cannot change after it is recorded."
        );
    }
}
