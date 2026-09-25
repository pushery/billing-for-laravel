<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Pushery\Billing\Models\InvoiceRecord;

/**
 * A settlement was canceled and issued again because its creator's standing was corrected.
 *
 * The two documents change what the platform owes the creator for a supply that was usually paid out
 * already. A creator found to be standard-rated is owed the tax on top of the payout; a creator found to
 * be a small business was paid tax they must not charge. The package writes the documents and moves no
 * money, so this is where a payout process learns the difference and settles it.
 *
 * `difference()` is what the replacement pays minus what the original paid: positive when the creator is
 * owed more, negative when they were paid too much.
 */
final readonly class SettlementRestated implements BillingDomainEvent
{
    public function __construct(
        public InvoiceRecord $original,
        public InvoiceRecord $cancellation,
        public InvoiceRecord $replacement,
    ) {}

    /** What the replacement pays minus what the original paid, in minor units of their currency. */
    public function difference(): int
    {
        return $this->replacement->total_minor - $this->original->total_minor;
    }
}
