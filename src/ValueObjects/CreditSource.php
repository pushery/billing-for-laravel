<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * What caused a credit-balance movement — an add-on purchase, a refund attempt, an order.
 *
 * It exists as its own type rather than as a second `Model` parameter on the ledger, and the reason is a
 * guard rather than taste. `CreditLedgerContainmentTest` treats ANY call taking two models as the shape of
 * value moving between parties, because that shape is the risk whatever the author meant by it — and a
 * cause passed as a model is indistinguishable from a second owner to a reader and to the check alike.
 *
 * Naming the cause as a distinct type keeps the ledger's invariant literally true instead of true-by-comment:
 * exactly one model enters the ledger, and it is always the owner whose balance moves. A source is a
 * reference written onto the entry; there is no path that credits or debits one, and it names no account.
 */
final readonly class CreditSource
{
    public function __construct(
        public string $type,
        public int|string $id,
    ) {}

    /**
     * The record that caused the movement, as its morph class and key.
     *
     * A record with no usable key is refused rather than coerced. A source is a pointer somebody will
     * follow when a balance is questioned, and `(string) null` is `''` — a pointer to nothing that reads
     * exactly like a pointer to something, which is the failure this whole ledger exists to prevent.
     *
     * @throws InvalidArgumentException when the record has not been persisted, or its key is not scalar
     */
    public static function for(Model $record): self
    {
        $key = $record->getKey();

        if (! is_int($key) && ! is_string($key)) {
            throw new InvalidArgumentException(
                'A credit source needs a persisted record with a scalar key; '.$record->getMorphClass().' has none.'
            );
        }

        return new self($record->getMorphClass(), $key);
    }
}
