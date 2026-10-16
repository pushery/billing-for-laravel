<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Pushery\Billing\Contracts\PaymentAccounts;
use Pushery\Billing\ValueObjects\PaymentAccount;

/**
 * The default for an install that runs one provider from its configuration: it knows no payment account.
 */
final class NoPaymentAccounts implements PaymentAccounts
{
    public function find(string $provider, string $key): ?PaymentAccount
    {
        return null;
    }
}
