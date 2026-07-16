<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Pushery\Billing\Contracts\IdentifiesCustomer;

/**
 * The provider is about to finalize a customer's next invoice.
 *
 * This is the last moment usage can still be put ON that invoice. Metered usage sits in a local outbox
 * and is flushed on a schedule — perfectly fine for a cycle that closes at some arbitrary later time, but
 * a cycle that closes NOW would finalize before the next scheduled flush and bill the customer a cycle
 * late for what they used. The upcoming-invoice signal is what lets a force-flush beat the close.
 *
 * It names only the customer: an upcoming invoice is a preview with no id of its own yet, and the one
 * thing an effect needs is whose outbox to drain.
 */
final readonly class InvoiceUpcoming implements BillingDomainEvent, IdentifiesCustomer
{
    public function __construct(
        public string $customerReference,
    ) {}
}
