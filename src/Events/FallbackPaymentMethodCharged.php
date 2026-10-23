<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\SerializesModels;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\PaymentMethod;

/**
 * A cycle the default payment method could not pay was paid with the owner's fallback.
 *
 * Dispatched once the payment is settled, after `PaymentSucceeded` for the same cycle, and at most once per cycle,
 * because a cycle tries its fallback at most once. `method` is the fallback as it was charged, and `reference` the
 * order of the cycle, the reference `PaymentSucceeded` carries where the provider gave none.
 *
 * The owner should hear of it: the default is still the method every later cycle is charged to first, and it just
 * failed. The package delivers `FallbackPaymentMethodChargedNotification` from it; replace that listener to route the
 * notice your own way.
 */
final readonly class FallbackPaymentMethodCharged implements BillingDomainEvent
{
    use SerializesModels;

    public function __construct(
        public Model $owner,
        public Money $amount,
        public PaymentMethod $method,
        public string $reference,
    ) {}
}
