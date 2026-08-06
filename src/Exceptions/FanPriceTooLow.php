<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use Pushery\Billing\ValueObjects\Money;
use RuntimeException;

/**
 * A fan chose a pay-what-you-want price below the floor the operator set.
 *
 * Refused on the server, because the floor is exactly the guarantee a client-side check cannot give: a
 * price the buyer picks is the one place the package's stance against price injection would otherwise
 * lapse, so the minimum is re-established here from config rather than trusted from the request.
 */
final class FanPriceTooLow extends RuntimeException
{
    public function __construct(
        public readonly Money $chosen,
        public readonly Money $minimum,
    ) {
        parent::__construct(
            "A chosen price of {$chosen->format()} is below the configured minimum of {$minimum->format()}. ".
            'The floor is enforced on the server because a buyer-chosen price is the one place the '.
            'anti-injection guarantee would otherwise be given up.'
        );
    }
}
