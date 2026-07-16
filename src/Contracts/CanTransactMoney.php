<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * A fail-closed eligibility gate run before any money-moving operation (charge, off-session charge,
 * subscribe, checkout, add-on purchase). It answers whether the owner may transact (age / KYC), and
 * denies unless positively eligible — the default is deny.
 */
interface CanTransactMoney
{
    public function check(Model $owner): bool;
}
