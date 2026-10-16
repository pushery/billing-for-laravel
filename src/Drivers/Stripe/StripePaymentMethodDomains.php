<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use Pushery\Billing\ValueObjects\PaymentAccount;
use Pushery\Billing\ValueObjects\WalletDomain;

/**
 * Registers a shop's domain with the Stripe account of its merchant, for Apple Pay, Google Pay, Link and PayPal on the
 * shop's own page.
 *
 * The merchant's own key registers it, so the domain belongs to the merchant's account. Stripe answers per wallet
 * whether the domain is active there.
 */
final readonly class StripePaymentMethodDomains
{
    public function __construct(
        private Repository $config,
    ) {}

    /**
     * Register a domain with the account, or find it where the account has it already.
     *
     * @param  string  $domain  the host name, such as `shop.example`, without a scheme or a path
     *
     * @throws InvalidArgumentException when the domain is no host name
     */
    public function register(PaymentAccount $account, string $domain): WalletDomain
    {
        $domain = strtolower(trim($domain));

        if (preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $domain) !== 1) {
            throw new InvalidArgumentException("A wallet domain is a host name such as `shop.example`, without a scheme or a path, and `{$domain}` is not.");
        }

        $version = $this->config->get('billing.stripe.api_version');
        $stripe = StripeAccountClient::for($account, is_string($version) && $version !== '' ? $version : StripeServiceProvider::STRIPE_API_VERSION);

        $registered = $stripe->paymentMethodDomains->all(['domain_name' => $domain, 'limit' => 1])->data[0]
            ?? $stripe->paymentMethodDomains->create(['domain_name' => $domain]);

        $read = $registered->toArray();
        $wallets = [];

        foreach ($read as $wallet => $setting) {
            if (is_string($wallet) && is_array($setting) && is_string($setting['status'] ?? null)) {
                $wallets[$wallet] = $setting['status'] === 'active';
            }
        }

        return new WalletDomain(
            is_string($read['domain_name'] ?? null) ? $read['domain_name'] : $domain,
            ($read['enabled'] ?? false) === true,
            $wallets,
        );
    }
}
