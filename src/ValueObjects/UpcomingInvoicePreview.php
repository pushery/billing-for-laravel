<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use DateTimeInterface;

/**
 * A best-effort preview of the next invoice — the date it will be charged and the amount. Returned
 * by the UpcomingInvoice contract, which may legitimately return null for drivers that cannot preview
 * (a local-engine driver), so the UI degrades rather than fabricating a figure.
 */
final readonly class UpcomingInvoicePreview
{
    public function __construct(
        public DateTimeInterface $date,
        public Money $amount,
    ) {}
}
