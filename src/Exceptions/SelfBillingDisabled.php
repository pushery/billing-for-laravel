<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;

/**
 * The self-billing engine was reached while self-billing is switched off.
 *
 * A platform that does not self-bill routes creators to the fallback lane — the creator submits their own
 * invoice — and never settles them here. So reaching the engine with the switch off is a caller mistake, not
 * a document to produce: the engine refuses loudly rather than issue one a disabled platform never meant to.
 */
final class SelfBillingDisabled extends RuntimeException
{
    public static function make(): self
    {
        return new self(
            'The self-billing engine was reached while billing.marketplace.self_billing.enabled is off. A '
            .'platform that does not self-bill routes creators to the fallback lane and never settles them '
            .'here — check the switch before calling the engine, or turn it on to self-bill.'
        );
    }
}
