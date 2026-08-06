<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Carbon\CarbonInterface;

/**
 * One movement on a merchant's payables account, with the document behind it.
 *
 * The document reference is the part that makes this a sub-ledger rather than a running total. A balance
 * anybody can recompute proves nothing on its own — what an audit asks is which documents make it up, and a
 * figure that cannot name them is an assertion, not a record.
 *
 * The sign says which way the platform's obligation moved: positive is more owed (a settlement was issued),
 * negative is less (it was paid out, or a correction took it back).
 */
final readonly class SubLedgerMovement
{
    public function __construct(
        public string $merchantType,
        public int|string $merchantId,
        public string $documentNumber,
        public CarbonInterface $occurredOn,
        public Money $amount,
    ) {}

    /** The key a merchant's movements group under — the morph pair, which is what identifies them. */
    public function merchantKey(): string
    {
        return $this->merchantType.':'.$this->merchantId;
    }
}
