<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

/**
 * One page of an owner's invoices — the neutral shape the Invoices contract returns. `hasMore`
 * drives the "load older" affordance without exposing a provider cursor.
 */
final readonly class InvoicePage
{
    /** @param list<Invoice> $rows */
    public function __construct(
        public array $rows,
        public bool $hasMore = false,
    ) {}

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }
}
