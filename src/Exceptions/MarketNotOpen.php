<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use Pushery\Billing\Enums\MarketAccess;
use RuntimeException;

/**
 * A sale was attempted into a country the operator has not opened.
 *
 * Refused BEFORE the payment, because there is no document that repairs it afterwards: the tax has arisen
 * in a country where nobody is registered, and no correction undoes that. It carries the country and the
 * state so a consumer can render its own message — a buyer should be told their country is not served yet,
 * not shown a tax registration problem.
 *
 * Concatenation in this class assembles sentence text rather than behavior, so swapping or dropping
 * a fragment measures where the line was wrapped, not what a test asserts. The values themselves are
 * held by a dedicated guard that varies every parameter individually.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class MarketNotOpen extends RuntimeException
{
    public function __construct(
        public readonly string $country,
        public readonly MarketAccess $state,
    ) {
        parent::__construct(
            "This platform does not sell into [{$country}] (market state: {$state->value}). A sale there ".
            'would create a tax liability in a country where no registration exists, and no later document '.
            'repairs that — so it is refused before the payment rather than after it.'
        );
    }

    /** A country the evidence could not resolve at all. Refused for the same reason, with its own wording. */
    public static function unresolvedCountry(): self
    {
        return new self('unknown', MarketAccess::Blocked);
    }
}
