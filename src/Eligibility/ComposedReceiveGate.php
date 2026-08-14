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

    /**
     * Compose the checks up front, which is what a container binding wants to write in one expression.
     *
     * This constructor exists because its ABSENCE was a defect rather than a gap. The published wiring
     * example passes its checks here; with no constructor declared, PHP accepted that argument in silence,
     * discarded it, and left a gate with zero checks — fail-closed, and therefore denying every merchant on
     * the platform. Nothing threw and nothing was logged, so it read from the outside exactly like a
     * merchant who had not finished onboarding.
     *
     * Variadic rather than an array parameter on purpose: each argument is type-checked at the call site,
     * and the old array form now raises a TypeError there instead of failing silently later.
     *
     * @param  callable(Model): bool  ...$checks
     */
    public function __construct(callable ...$checks)
    {
        $this->checks = array_values($checks);
    }

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
