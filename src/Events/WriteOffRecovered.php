<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Pushery\Billing\Models\InvoiceRecord;
use Pushery\Billing\ValueObjects\Money;

/**
 * Money arrived against a receivable that had been written off as uncollectible — the write-off was wrong.
 *
 * ## Why this is an event and not a correction
 *
 * The correcting documents already have an owner: the refund cascade issues them on both legs of the chain,
 * and a second path that also issued documents would be two answers to "what does this correction look
 * like". This event says WHAT HAPPENED; the cascade decides what it costs.
 *
 * ## What it means, and what it does not
 *
 * A correction issued because the consideration would not be received is a judgement about the future. The
 * money turning up says the judgement was wrong, so the tax goes back. That is the ONLY case this event is
 * raised for — a correction issued because the consideration was handed back can never be reopened, and a
 * payment afterwards is a new transaction with its own document. Nothing in the amounts distinguishes the
 * two afterwards, which is why the reason is read off the document rather than inferred.
 *
 * `$correction` is the document that is being put back, not the original invoice. `$received` is what
 * actually arrived, which may be less than what was written off — a partial recovery is still a recovery,
 * and the cascade is what decides how much of the tax follows it.
 */
final readonly class WriteOffRecovered implements BillingDomainEvent
{
    public function __construct(
        public InvoiceRecord $correction,
        public Money $received,
        public string $paymentReference,
    ) {}
}
