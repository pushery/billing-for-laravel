<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\ValueObjects\Money;

/**
 * A sale was paid for, and the merchant's share did not move.
 *
 * Raised on a separate transfer, where moving the share is a second call made after the buyer's payment went
 * through, and that call failed. The buyer has paid, the merchant has not been, and the charge row stays
 * `pending` until the share moves. Nothing else in the package would ever say so: no webhook arrives for a
 * transfer that was never created.
 *
 * A consumer listens to alert whoever owns the merchant relationship, or to hold whatever it releases on
 * payment. `billing:marketplace:retry-transfers` moves the share once the cause is fixed.
 */
final readonly class MerchantShareNotMoved implements BillingDomainEvent
{
    public function __construct(
        public Model $merchant,
        public string $provider,
        public string $chargeReference,
        /** The share the merchant is owed, read off the row the sale wrote. */
        public Money $amount,
        /** What the provider or the call said. Worth showing to an operator, not to the merchant. */
        public string $reason,
    ) {}
}
