<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use DateTimeInterface;
use Pushery\Billing\Enums\InvoiceStatus;

/**
 * A package-owned invoice DTO returned across the Invoices contract. The Stripe driver hydrates it
 * from a Stripe invoice; the local engine builds it directly. Views render this shape,
 * never a provider object — so a gap-free, immutable local number can sit alongside a Stripe id.
 */
final readonly class Invoice
{
    public function __construct(
        public string $id,
        public DateTimeInterface $date,
        public Money $total,
        public InvoiceStatus $status,
        public ?string $number = null,
        public ?string $downloadUrl = null,
    ) {}

    public function isPaid(): bool
    {
        return $this->status->isPaid();
    }

    /** Whether a PDF can be retrieved for this invoice. */
    public function isDownloadable(): bool
    {
        return $this->downloadUrl !== null;
    }
}
