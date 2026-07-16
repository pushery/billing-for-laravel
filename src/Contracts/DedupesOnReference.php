<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\Events\BillingDomainEvent;

/**
 * An effect that must fire once per DOMAIN thing, not once per delivery.
 *
 * By default an effect is deduped on the provider's event id: run once per delivery, skip the
 * redelivery. That is right for most effects. It is WRONG for the dunning notice — a provider mints a
 * FRESH event id for every retry of the same failing invoice, so deduping on the event id would send the
 * customer one "your payment failed" mail per retry. Such an effect implements this and returns the
 * reference it actually wants to be unique on (the invoice), and the delivery machinery dedups on that.
 */
interface DedupesOnReference
{
    public function dedupReference(BillingDomainEvent $event): string;
}
