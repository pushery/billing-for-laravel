<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Pushery\Billing\ValueObjects\Money;

/**
 * The buyer paid a sale at the counter with a card, and the provider confirmed it.
 *
 * This is the moment the sale is documented: its receipt is issued now and not when the sale went onto the reader,
 * because until the card was presented nothing had been sold. The event names the payment and what the provider
 * collected; the place and the tax come from the row the package kept when the sale went up.
 */
final readonly class InPersonSalePaid implements BillingDomainEvent
{
    public function __construct(
        public string $provider,
        /** The provider's reference for the payment, as the sale's row records it. */
        public string $paymentReference,
        /** What the provider collected on the card. */
        public Money $amount,
    ) {}
}
