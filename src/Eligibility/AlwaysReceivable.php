<?php

declare(strict_types=1);

namespace Pushery\Billing\Eligibility;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\CanReceiveMoney;

/**
 * The package default receiving gate: every merchant may receive.
 *
 * It matches the paying side's default, and it is safe for exactly the same reason: without the
 * marketplace switched on nothing ever routes money to a merchant, so the gate is never consulted. A
 * marketplace consumer binds {@see ComposedReceiveGate} with the provider-capability check and its own
 * predicates instead — which the go-live checklist and the routed-charge path both expect.
 */
final readonly class AlwaysReceivable implements CanReceiveMoney
{
    public function check(Model $merchant): bool
    {
        return true;
    }
}
