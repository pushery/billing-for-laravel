<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use RuntimeException;

/**
 * The offline access token the application holds for each shop that installed it.
 *
 * The application receives the token when a shop installs the app and keeps it wherever its Shopify
 * integration keeps it, so the package asks rather than stores: an implementation reads it from that place.
 * The Shopify driver sends it with every Admin API request it makes for the shop.
 */
interface ShopifyAccessTokens
{
    /**
     * The token for a shop, named by its permanent `*.myshopify.com` domain.
     *
     * @throws RuntimeException when the application holds no token for the shop
     */
    public function for(string $shop): string;
}
