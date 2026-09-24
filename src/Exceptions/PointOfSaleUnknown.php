<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use InvalidArgumentException;

/**
 * A sale was to be placed at a point of sale nobody named.
 *
 * Refused rather than placed where the buyer is. That fallback is exactly the answer a point-of-sale supply
 * replaces: it would tax a shop sale in the buyer's country, or zero it for a business from abroad, and every
 * document would still look ordinary.
 *
 * Concatenation in this class assembles sentence text rather than behavior, so swapping or dropping
 * a fragment measures where the line was wrapped, not what a test asserts. The values themselves are
 * held by a dedicated guard that varies every parameter individually.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class PointOfSaleUnknown extends InvalidArgumentException
{
    public static function missing(): self
    {
        return new self('A sale made in person is taxed where it is made, and no point of sale was given. Pass the country the sale is made in as soldAt.');
    }

    public static function notACountry(string $given): self
    {
        return new self(sprintf('The point of sale "%s" is not a two-letter country code. Pass the country the sale is made in, such as DE.', $given));
    }
}
