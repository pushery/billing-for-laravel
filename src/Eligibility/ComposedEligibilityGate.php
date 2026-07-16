<?php

declare(strict_types=1);

namespace Pushery\Billing\Eligibility;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\CanTransactMoney;

/**
 * A fail-closed eligibility gate: it denies unless at least one check is registered AND every
 * registered check passes. A project adds its own checks (age, KYC, …) via require(); with none
 * registered the gate denies, so money never moves before eligibility is positively established.
 */
final class ComposedEligibilityGate implements CanTransactMoney
{
    /** @var list<callable(Model): bool> */
    private array $checks = [];

    /**
     * @param  callable(Model): bool  $check
     */
    public function require(callable $check): self
    {
        $this->checks[] = $check;

        return $this;
    }

    public function check(Model $owner): bool
    {
        if ($this->checks === []) {
            return false;
        }

        return array_all($this->checks, fn (callable $check): bool => $check($owner) === true);
    }
}
