<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;

/**
 * The Shopify driver was selected, but something it cannot work without is missing.
 *
 * Each reason names the setting or the binding that answers it, because the three are answered in three
 * different places: the client secret in the environment, the access tokens in a binding of the application,
 * and the shop domain on the owner's row.
 *
 * Concatenation in this class assembles sentence text rather than behavior, so swapping or dropping a fragment
 * measures where the line was wrapped, not what a test asserts.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class ShopifyNotConfigured extends RuntimeException
{
    public static function missingClientSecret(): self
    {
        return new self(
            'The Shopify driver is selected but no client secret is configured. Set `billing.shopify.client_secret` '.
            '(BILLING_SHOPIFY_CLIENT_SECRET) to the client secret of your Shopify app: Shopify signs every webhook '.
            'with it, and without it no subscription change is ever accepted.'
        );
    }

    public static function noAccessTokens(): self
    {
        return new self(
            'The Shopify driver needs the access token your app holds for each shop, and nothing provides it. Bind '.
            'an implementation of `Pushery\Billing\Contracts\ShopifyAccessTokens` in a service provider of yours; '.
            'it returns the offline access token for a shop by its `*.myshopify.com` domain.'
        );
    }

    public static function noShopDomain(string $owner, string $column): self
    {
        return new self(
            "The billing owner {$owner} has no Shopify shop domain in its `{$column}` column. The Shopify driver reads ".
            'the shop of an owner from `billing.customer.column`, so the column has to hold the permanent '.
            '`*.myshopify.com` domain of the shop that installed the app.'
        );
    }
}
