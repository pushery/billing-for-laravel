<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Illuminate\Queue\SerializesModels;
use Pushery\Billing\Models\Order;

/**
 * An order was paid for, and no invoice was issued for it.
 *
 * Raised where a local engine issues the invoice itself, after the money has moved. Two failures end there without
 * a document. The tax of the supply could not be determined, and a numbered invoice that states no tax for a
 * taxable supply is not a complete one, so none is issued. Or writing the document failed. Either failure also goes
 * to the application's exception handler, with its trace.
 *
 * A consumer listens to alert whoever keeps the books. Once the cause is fixed, `OrderInvoiceIssuer::issue()` issues
 * the document for the order, and it issues no second one for an order that has one.
 */
final readonly class InvoiceNotIssued implements BillingDomainEvent
{
    use SerializesModels;

    public function __construct(
        public Order $order,
        /** What the failure said. Worth showing to an operator, not to the customer. */
        public string $reason,
    ) {}
}
