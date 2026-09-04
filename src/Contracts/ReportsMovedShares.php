<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\ValueObjects\MovedShare;

/**
 * Read back what a provider says about a transfer the package already recorded.
 *
 * Separate from `MovesMerchantShare` on purpose: moving money and auditing it are different privileges and
 * different failure modes, and a driver may honestly support one without the other. A provider that routes
 * the share as part of the payment makes no transfer call at all and has nothing to read back.
 */
interface ReportsMovedShares
{
    /**
     * The provider's current view of one transfer, or null when it has no record of that reference.
     *
     * Null is a FINDING, not an absence. A reference in the local journal that the provider does not know is
     * the most serious disagreement of all: the package believes it paid a merchant and the provider says
     * no such payment exists.
     */
    public function movedShare(string $transferReference): ?MovedShare;
}
