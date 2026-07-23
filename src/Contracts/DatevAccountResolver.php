<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\Enums\DatevTransaction;
use Pushery\Billing\ValueObjects\DatevAccount;

/**
 * Resolves a business transaction (and, for OSS, a destination country) to the DATEV account it books to.
 *
 * This sits in FRONT of the export: the export names a transaction and gets an account back, so it never
 * reads a raw account number from config itself. A transaction that cannot be resolved is refused, not
 * booked to a default account — a wrong account is a silent accounting error that surfaces only at audit.
 */
interface DatevAccountResolver
{
    public function resolve(DatevTransaction $transaction, ?string $country = null): DatevAccount;
}
