<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use Illuminate\Contracts\Config\Repository;

/**
 * How a Checkout Session asks the buyer for a tax ID, the same for a subscription and for a one-time purchase.
 *
 * Whether it asks is `billing.checkout.tax_id_collection`: unset, a session asks where the provider computes tax;
 * set, it asks or not as the setting says. Where it asks, Stripe saves the ID and the business name that comes with
 * it onto the customer, and for a customer that exists already, which every customer is here because one is resolved
 * before the session, Stripe requires permission to update its name and its address.
 */
final class StripeTaxIdCollection
{
    /** Whether a session asks for a tax ID where its caller leaves the choice to the installation. */
    public static function byDefault(Repository $config, bool $providerTax): bool
    {
        $configured = $config->get('billing.checkout.tax_id_collection');

        return $configured === null ? $providerTax : $configured !== false;
    }

    /**
     * What a session may save onto the existing customer: the address automatic tax needs, and with a tax ID the name
     * as well.
     *
     * @return array{address: 'auto', name?: 'auto'}
     */
    public static function customerUpdate(bool $collectsTaxId): array
    {
        return $collectsTaxId ? ['address' => 'auto', 'name' => 'auto'] : ['address' => 'auto'];
    }
}
