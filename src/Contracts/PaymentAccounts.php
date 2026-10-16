<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\ValueObjects\PaymentAccount;

/**
 * Where the package finds a payment account again by its provider and key.
 *
 * The application binds its own implementation, reading the account of a shop from wherever it keeps it.
 * The package asks when a webhook arrives on an account's address and needs the account to verify and read
 * it, and a job does the same, because an account is never serialized.
 *
 * The default answers null for every pair: an install that runs one provider from its configuration has no
 * payment accounts.
 */
interface PaymentAccounts
{
    /** The account of this provider under this key, or null when the application knows none. */
    public function find(string $provider, string $key): ?PaymentAccount;
}
