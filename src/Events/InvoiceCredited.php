<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Pushery\Billing\Contracts\IdentifiesCustomer;
use Pushery\Billing\ValueObjects\InvoiceCorrectionSnapshot;

/**
 * @deprecated Renamed to {@see InvoiceCorrected}. The old name conflated this correcting document (a
 * cancellation or amendment of an invoice) with a self-billing credit note (§ 14 Abs. 2 S. 5 UStG, type
 * code 389), which is a different document. This class remains for one deprecation window: `InvoiceCorrected`
 * fires it through the framework dispatcher too, so an existing `Event::listen(InvoiceCredited::class)`
 * keeps being called. Migrate to `InvoiceCorrected` and read `$event->correction`; this class and the alias
 * firing will be removed in a later release. The package's own effects listen on `InvoiceCorrected` only, so
 * a correction is never persisted twice.
 */
final readonly class InvoiceCredited implements BillingDomainEvent, IdentifiesCustomer
{
    public string $customerReference;

    public function __construct(public InvoiceCorrectionSnapshot $correction)
    {
        $this->customerReference = $correction->customerReference;
    }
}
