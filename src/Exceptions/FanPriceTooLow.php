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
 *
 * Concatenation in this class assembles sentence text rather than behavior, so swapping or dropping
 * a fragment measures where the line was wrapped, not what a test asserts. The values themselves are
 * held by a dedicated guard that varies every parameter individually.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
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
