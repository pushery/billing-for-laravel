<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Pushery\Billing\Contracts\IdentifiesCustomer;

/**
 * A subscription cycle's invoice was created as a draft, the one moment a routed cycle's commission can still be set.
 *
 * A destination subscription carries its commission as a percentage, and the provider applies it to the invoice
 * total, the buyer's tax included. A draft still takes an absolute fee, so this is where the commission is set on the
 * net before the provider finalizes the invoice.
 *
 * Narrow for the reason RoutedSubscriptionInvoicePaid is narrow: its handler reaches the provider, and the
 * subscription reference lets it ask the local row first and stop there for every subscription that routes nothing.
 */
final readonly class RoutedSubscriptionInvoiceDrafted implements BillingDomainEvent, IdentifiesCustomer
{
    public function __construct(
        public string $customerReference,
        /** The provider's invoice id, the draft the fee is set on. */
        public string $invoiceReference,
        /** The provider's subscription id, so the handler can ask whether this one routes at all. */
        public string $subscriptionReference,
    ) {}
}
