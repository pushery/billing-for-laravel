<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

/**
 * A shop's domain as a provider registered it for the wallets a page offers there, such as Apple Pay and Google Pay.
 */
final readonly class WalletDomain
{
    /**
     * @param  string  $domain  the host name, such as `shop.example`
     * @param  bool  $enabled  whether the provider offers wallets on the domain at all
     * @param  array<string, bool>  $wallets  per wallet in the provider's terms, such as `apple_pay`, whether it is active there
     */
    public function __construct(
        public string $domain,
        public bool $enabled,
        public array $wallets,
    ) {}
}
