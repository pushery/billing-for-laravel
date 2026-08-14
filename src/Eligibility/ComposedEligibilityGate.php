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
     * Compose the checks up front, so a container binding can be written as one expression.
     *
     * Kept in step with its sibling `ComposedReceiveGate` deliberately: two gates that differ only in which
     * contract they satisfy should not differ in how a consumer builds them, and the receiving side had a
     * documented example that silently produced a gate denying everyone. Same shape, same guarantee.
     *
     * @param  callable(Model): bool  ...$checks
     */
    public function __construct(callable ...$checks)
    {
        $this->checks = array_values($checks);
    }

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
