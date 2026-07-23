<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

/**
 * What a payment driver can do natively, so the package/UI can query capabilities and fill the gaps
 * with its own local engine. This is how a local-engine driver slots in without reducing Stripe: Stripe
 * reports rich native capabilities; the others report fewer, and the package supplies the rest.
 */
final readonly class DriverCapabilities
{
    /**
     * @param  list<string>  $availablePaymentMethods
     * @param  list<string>  $recurringCapableMethods
     */
    public function __construct(
        public bool $supportsHostedPortal = false,
        public bool $supportsProviderTax = false,
        public bool $supportsMeteredNative = false,
        public bool $supportsProviderProration = false,
        public bool $supportsProviderCredit = false,
        public array $availablePaymentMethods = [],
        public array $recurringCapableMethods = [],
    ) {}

    public function offersMethod(string $method): bool
    {
        return in_array($method, $this->availablePaymentMethods, true);
    }

    public function canRecurWith(string $method): bool
    {
        return in_array($method, $this->recurringCapableMethods, true);
    }
}
