<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\Enums\SupplyRegime;
use Pushery\Billing\Enums\TaxArchetype;

/**
 * Decides, once, which shape a routed sale has.
 *
 * Once is the operative word: the answer is written onto the transaction at the sale and never asked
 * again. A resolver consulted later would be answering about today's configuration, and configuration
 * changes — legitimately — while documents already issued do not.
 */
interface SupplyRegimeResolver
{
    /**
     * The regime for a sale of this kind.
     *
     * @param  ?TaxArchetype  $archetype  what is being sold, where the platform knows. Null means it does
     *                                    not, and the configured default answers — which is why the default
     *                                    is a deliberate setting rather than a fallback.
     */
    public function resolveFor(?TaxArchetype $archetype = null): SupplyRegime;
}
