<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use Pushery\Billing\Contracts\BillingDriver;
use Pushery\Billing\Contracts\BillingEngine;
use Pushery\Billing\Contracts\PaymentRails;
use Pushery\Billing\ValueObjects\DriverCapabilities;

/**
 * The default driver: Stripe Billing via Cashier. It reports rich native capabilities (hosted portal,
 * provider tax, native metering, provider proration), so the package delegates to Stripe rather than
 * filling those gaps with its own engine — the opposite of a local-engine driver.
 *
 * A capability is a promise the PACKAGE keeps, not a description of what the provider could do. Metered
 * usage counts as native here because the package actually reports it to Stripe's meters
 * (StripeUsageReporter); a driver that merely could, but does not, must report false, or an app that
 * trusts the flag would bill no usage at all.
 */
final readonly class StripeDriver implements BillingDriver
{
    public function __construct(private PaymentRails $rails) {}

    public function name(): string
    {
        return 'stripe';
    }

    public function rails(): PaymentRails
    {
        return $this->rails;
    }

    public function engine(): BillingEngine
    {
        return new CashierBillingEngine;
    }

    public function capabilities(): DriverCapabilities
    {
        return new DriverCapabilities(
            supportsHostedPortal: true,
            supportsProviderTax: true,
            supportsMeteredNative: true,
            supportsProviderProration: true,
            supportsProviderCredit: true,
            availablePaymentMethods: ['card', 'sepa_debit', 'link'],
            recurringCapableMethods: ['card', 'sepa_debit', 'link'],
        );
    }
}
