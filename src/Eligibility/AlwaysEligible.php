<?php

declare(strict_types=1);

namespace Pushery\Billing\Eligibility;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\CanTransactMoney;

/**
 * The package default eligibility gate: everyone may transact. Eligibility (age, KYC, …) is a
 * project-specific requirement, so money flows out of the box; an app that needs to gate it binds the
 * fail-closed ComposedEligibilityGate with its own checks instead.
 */
final readonly class AlwaysEligible implements CanTransactMoney
{
    public function check(Model $owner): bool
    {
        return true;
    }
}
