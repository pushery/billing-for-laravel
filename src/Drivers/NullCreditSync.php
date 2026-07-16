<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\CreditSync;
use Pushery\Billing\ValueObjects\Money;

/**
 * The default: a credit balance is tracked locally and never pushed to a provider.
 *
 * A driver that cannot hold a customer balance keeps the package ledger as the whole story — the owner
 * still earns, sees and (for charges the package itself raises) spends the balance. Only a driver that can
 * mirror it to the provider binds a real CreditSync in place of this.
 */
final class NullCreditSync implements CreditSync
{
    public function push(Model $owner, Money $signedDelta, string $reference): void
    {
        // Nothing to push: the provider does not hold a balance for us, so the local ledger is authoritative.
    }
}
