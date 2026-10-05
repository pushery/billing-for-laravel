<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks\Effects;

use Pushery\Billing\Contracts\DedupesOnReference;
use Pushery\Billing\Events\AddonRefunded;
use Pushery\Billing\Events\BillingDomainEvent;
use Pushery\Billing\Support\AddonRefunds;
use RuntimeException;

/**
 * Claws back the credit granted for a one-time add-on when its charge is refunded or a dispute over it
 * is lost. The reverse-debit-audit is done atomically by AddonRefunds (shared with the admin refund
 * path); this effect is just the webhook wiring. Owner resolution is on the purchase row the payment
 * reference matches — no CustomerDirectory, which is what lets it work even for a provider dispute
 * object that carries no customer.
 */
final readonly class ReverseAddonPurchase implements DedupesOnReference
{
    public function __construct(private AddonRefunds $refunds) {}

    public function __invoke(AddonRefunded $event): void
    {
        $this->refunds->reverse($event->paymentReference, $event->cumulativeRefunded, $event->reason);
    }

    /**
     * Once per refunded state of the payment: the payment, its cumulative refunded total and why it came back.
     *
     * The event carries the cumulative figure, so a redelivery repeats it and a further refund raises it. Keyed on
     * the delivery instead, a provider that pings every change to a payment under one key had its second refund
     * dropped as a duplicate of the first: Mollie does, because a refund leaves the payment `paid`. The reversal,
     * the credit note and the access revocation name the state with the same expression, so the three effects on
     * this event agree about what "the same refund" means.
     */
    public function dedupReference(BillingDomainEvent $event): string
    {
        if (! $event instanceof AddonRefunded) {
            throw new RuntimeException('ReverseAddonPurchase only handles AddonRefunded events.');
        }

        return sprintf('%s:refunded:%d:%s:%s', $event->paymentReference, $event->cumulativeRefunded->minorUnits, $event->cumulativeRefunded->currency, $event->reason ?? 'refund');
    }
}
