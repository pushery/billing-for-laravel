<?php

declare(strict_types=1);

namespace Pushery\Billing\Eligibility;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\CanReceiveMoney;

/**
 * A fail-closed receiving gate: it denies unless at least one check is registered AND every registered
 * check passes.
 *
 * The zero-check denial is the part that matters. A gate that permitted everything until somebody
 * remembered to configure it would be at its most permissive exactly when a marketplace is new — the
 * moment when nobody has verified anyone yet and every merchant account is half-onboarded.
 *
 * A platform composes its own requirements on top of the provider's: the provider verifies identity and
 * banking, the platform decides who it is willing to pay. Both must hold, so the checks are AND-ed.
 */
final class ComposedReceiveGate implements CanReceiveMoney
{
    /** @var list<callable(Model): bool> */
    private array $checks = [];

    /** @param  callable(Model): bool  $check */
    public function require(callable $check): self
    {
        $this->checks[] = $check;

        return $this;
    }

    public function check(Model $merchant): bool
    {
        if ($this->checks === []) {
            return false;
        }

        return array_all($this->checks, fn (callable $check): bool => $check($merchant) === true);
    }
}
