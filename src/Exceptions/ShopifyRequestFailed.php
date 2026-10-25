<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A request to a shop's Admin API did not give the answer the act needs.
 *
 * One exception for every layer the request can fail at: the shop out of reach, an HTTP status other than 200,
 * an error of the GraphQL layer, or a mutation that answered with `userErrors`. The message names the shop and
 * what Shopify said, and never the access token.
 *
 * Concatenation in this class assembles sentence text rather than behavior, so swapping or dropping a fragment
 * measures where the line was wrapped, not what a test asserts.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class ShopifyRequestFailed extends RuntimeException
{
    public static function notAShop(string $shop): self
    {
        return new self("`{$shop}` is not the permanent domain of a Shopify shop, which ends in `.myshopify.com`. Nothing was sent.");
    }

    public static function unreachable(string $shop, Throwable $previous): self
    {
        return new self("The Admin API of {$shop} could not be reached: {$previous->getMessage()}", 0, $previous);
    }

    public static function status(string $shop, int $status): self
    {
        return new self("The Admin API of {$shop} answered with HTTP {$status}.");
    }

    /**
     * @param  list<string>  $messages
     */
    public static function graphql(string $shop, array $messages): self
    {
        return new self("The Admin API of {$shop} refused the request: ".implode(' ', $messages));
    }

    public static function noPayload(string $shop, string $field): self
    {
        return new self("The Admin API of {$shop} answered without the `{$field}` it was asked for.");
    }

    /**
     * @param  list<string>  $messages
     */
    public static function userErrors(string $shop, string $field, array $messages): self
    {
        return new self("`{$field}` failed on {$shop}: ".implode(' ', $messages));
    }
}
