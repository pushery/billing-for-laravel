<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Drivers\NullCreditSync;
use Pushery\Billing\ValueObjects\Money;

/**
 * Pushes a change in an owner's package-owned credit balance to the provider, so the credit is actually
 * spent — applied against the customer's next provider-raised invoice — rather than only tracked locally.
 *
 * The package's ledger is the source of truth; this is the optional bridge to a provider that can hold a
 * balance. A driver without one binds the no-op {@see NullCreditSync}, and the
 * basic feature (earning, showing and — for locally-collected charges — netting the balance) keeps working
 * on every driver. Only the "reduce the next Stripe invoice automatically" part needs this.
 *
 * The delta follows the PACKAGE's sign convention: a positive amount is credit the customer gained (their
 * next invoice should be smaller), a negative amount is credit clawed back. A driver translates that to its
 * own convention. The reference is the money operation's idempotency key: pushing the same reference twice
 * must not move the provider balance twice.
 */
interface CreditSync
{
    public function push(Model $owner, Money $signedDelta, string $reference): void;
}
