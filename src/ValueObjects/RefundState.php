<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Pushery\Billing\Contracts\ReadsRefunds;
use Pushery\Billing\Enums\RefundStatus;

/**
 * One refund of a payment as the provider holds it now, read back through {@see ReadsRefunds}.
 */
final readonly class RefundState
{
    public function __construct(
        /** The provider's reference for the refund, the one a {@see RefundResult} reported when it was made. */
        public string $reference,
        /**
         * The idempotency key the refund was made under, as `refund()` stored it with the refund. Null for a refund
         * made without a key, and for one made outside the package, at the provider's dashboard for instance.
         */
        public ?string $key,
        public RefundStatus $status,
        public Money $amount,
    ) {}

    /** Whether the refund can still reach the customer, which a failed or canceled refund cannot. */
    public function standing(): bool
    {
        return $this->status === RefundStatus::Pending || $this->status === RefundStatus::Refunded;
    }
}
