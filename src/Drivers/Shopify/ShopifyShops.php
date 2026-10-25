<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Shopify;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Exceptions\ShopifyNotConfigured;

/**
 * The shop an owner is: the permanent `*.myshopify.com` domain on the owner's row.
 *
 * It is read from the column `billing.customer.column` names, which is also the column a webhook finds the
 * owner by. So the domain is both the owner's customer reference and the host of every Admin API request made
 * for the owner, and one setting keeps the two the same.
 */
final readonly class ShopifyShops
{
    public function __construct(private Repository $config) {}

    /**
     * @throws ShopifyNotConfigured when the owner's row holds no shop domain
     */
    public function of(Model $owner): string
    {
        $column = $this->column();
        $shop = $owner->getAttribute($column);
        $shop = is_string($shop) ? trim($shop) : '';

        if (! ShopifyAdmin::isShop($shop)) {
            $key = $owner->getKey();

            throw ShopifyNotConfigured::noShopDomain($owner->getMorphClass().'#'.(is_scalar($key) ? (string) $key : '?'), $column);
        }

        return $shop;
    }

    private function column(): string
    {
        $column = $this->config->get('billing.customer.column', 'stripe_id');

        return is_string($column) && $column !== '' ? $column : 'stripe_id';
    }
}
