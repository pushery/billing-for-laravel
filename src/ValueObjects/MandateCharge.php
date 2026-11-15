<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Pushery\Billing\Contracts\ChargesOnTheMandate;
use Pushery\Billing\Models\Order;

/**
 * Where a charge on the mandate stands once the call returns ({@see ChargesOnTheMandate}).
 *
 * The order is null where the lines came to nothing, so nothing was written. Otherwise its status says the rest: paid,
 * processing while a bank debit waits to settle, or open. An open order waits for the day its notice of a direct debit
 * allows, for the commit of the transaction the call was made in, or, with a failure, for the owner's next paid cycle.
 *
 * The failure is `no_usable_mandate` where the owner has no mandate that can be charged off-session, the reason the
 * provider gave where it refused, and null otherwise.
 */
final readonly class MandateCharge
{
    public function __construct(
        public ?Order $order,
        public ?string $failure = null,
    ) {}
}
