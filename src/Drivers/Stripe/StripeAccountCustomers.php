<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\ValueObjects\PaymentAccount;
use Pushery\Billing\ValueObjects\PaymentAddress;

/**
 * Creates a customer on a Stripe payment account, for the payments that belong to one.
 *
 * A bank transfer on Stripe's checkout page is paid into the balance of a customer of the account, and Stripe opens one
 * account for each customer's transfers. So the application creates the customer once per person who buys, keeps the
 * `cus_…` id, and names it as the `customerReference` of every charge of that person. The name and the email come from
 * the address, and an idempotency key makes a creation that is sent again return the customer the first one created.
 */
final readonly class StripeAccountCustomers
{
    public function __construct(private Repository $config) {}

    /**
     * @param  array<string, string|int>  $metadata  values to keep with the customer, such as the shop and your own id
     * @return string the customer's id on the account, `cus_…`
     */
    public function create(PaymentAccount $account, ?PaymentAddress $address = null, array $metadata = [], ?string $idempotencyKey = null): string
    {
        $version = $this->config->get('billing.stripe.api_version');
        $client = StripeAccountClient::for($account, is_string($version) && $version !== '' ? $version : StripeServiceProvider::STRIPE_API_VERSION);
        $name = trim(implode(' ', array_filter([$address?->givenName, $address?->familyName], is_string(...))));
        $location = array_filter([
            'line1' => $address?->streetAndNumber,
            'line2' => $address?->streetAdditional,
            'postal_code' => $address?->postalCode,
            'city' => $address?->city,
            'state' => $address?->region,
            'country' => $address?->country,
        ], is_string(...));

        $params = array_filter([
            'name' => $name !== '' ? $name : $address?->organizationName,
            'email' => $address?->email,
            'phone' => $address?->phone,
            'address' => $location === [] ? null : $location,
            'metadata' => $metadata === [] ? null : $metadata,
        ], $this->isGiven(...));

        // @phpstan-ignore argument.type (the SDK's generated shape cannot express a payload assembled at run time)
        return $client->customers->create($params, $idempotencyKey === null ? [] : ['idempotency_key' => $idempotencyKey])->id;
    }

    /**
     * Whether a field of the customer goes to Stripe, which takes no null for it.
     *
     * @param  mixed  $value  a field of the customer, a string or the parts of its address
     */
    private function isGiven(mixed $value): bool
    {
        return $value !== null;
    }
}
