<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks\Effects;

use Pushery\Billing\Events\AddonRefunded;
use Pushery\Billing\Support\AddonRefunds;

/**
 * Claws back the credit granted for a one-time add-on when its charge is refunded or a dispute over it
 * is lost. The reverse-debit-audit is done atomically by AddonRefunds (shared with the admin refund
 * path); this effect is just the webhook wiring. Owner resolution is on the purchase row the payment
 * reference matches — no CustomerDirectory, which is what lets it work even for a provider dispute
 * object that carries no customer.
 */
final readonly class ReverseAddonPurchase
{
    public function __construct(private AddonRefunds $refunds) {}

    public function __invoke(AddonRefunded $event): void
    {
        $this->refunds->reverse($event->paymentReference, $event->cumulativeRefunded, $event->reason);
    }
}
