<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Pushery\Billing\Enums\RecapitulativeSupplyKind;

/**
 * One line of a recapitulative statement: one buyer, one kind of supply, and the taxable amounts summed.
 */
final readonly class RecapitulativeStatementLine
{
    public function __construct(
        /** The buyer's VAT id, with the prefix of its member state. */
        public string $vatId,
        public RecapitulativeSupplyKind $kind,
        /** The taxable amounts in minor units; negative where the period's corrections outweigh its sales. */
        public int $netMinor,
    ) {}

    /** The member state the VAT id was issued by, which is its prefix. */
    public function country(): string
    {
        return substr($this->vatId, 0, 2);
    }

    /** What makes two contributions one line: the same buyer and the same kind of supply. */
    public function key(): string
    {
        return $this->vatId.'|'.$this->kind->value;
    }
}
