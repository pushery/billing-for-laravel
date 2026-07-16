<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

/**
 * The outcome of a PaymentRails refund. Provider-neutral so the engine never inspects a raw
 * provider refund object.
 */
final readonly class RefundResult
{
    public function __construct(
        public bool $successful,
        public string $reference,
        public Money $amount,
    ) {}

    public function failed(): bool
    {
        return ! $this->successful;
    }
}
