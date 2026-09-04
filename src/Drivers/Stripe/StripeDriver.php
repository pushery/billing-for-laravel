<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use Pushery\Billing\Contracts\BillingDriver;
use Pushery\Billing\Contracts\BillingEngine;
use Pushery\Billing\Contracts\MarketplaceRails;
use Pushery\Billing\Contracts\PaymentRails;
use Pushery\Billing\Contracts\RoutesMoney;
use Pushery\Billing\Exceptions\MarketplaceUnsupported;
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
final readonly class StripeDriver implements BillingDriver, RoutesMoney
{
    public function __construct(
        private PaymentRails $rails,
        /**
         * The Connect rails, or null on an install that never built them.
         *
         * Nullable rather than required because this class is public surface a consumer may construct, and
         * a new required argument is a fatal error in code this package does not own. A driver built
         * without them refuses at the point of use with the same message it always gave, rather than at
         * boot with a different one.
         */
        private ?MarketplaceRails $marketplace = null,
    ) {}

    /**
     * The marketplace rails this driver routes through.
     *
     * Implementing {@see RoutesMoney} at all is the change: the shipped driver did not, so
     * `BillingManager::marketplaceRails()` could only ever throw on it and every marketplace capability
     * behind that call was unreachable. The go-live checkpoint reported exactly that, and was right to.
     */
    public function marketplaceRails(): MarketplaceRails
    {
        return $this->marketplace ?? throw MarketplaceUnsupported::driverCannotRoute($this->name());
    }

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
            // Answered from the RAILS, not from what Stripe is able to do. Stripe supports destination
            // charges everywhere; this driver can only make them on an install that built the Connect
            // rails, and the rule this package states twice over is that a capability is a promise the
            // PACKAGE keeps. An install without them refuses at the point of use, so a flag reading true
            // would send a screen to a lane that answers with an exception.
            //
            // It was omitted entirely before, which defaulted it to false on EVERY install — including a
            // fully wired marketplace. Nothing went red, because nothing in the shipped tree read it: the
            // guard decides on `instanceof RoutesMoney`, and the flag was set to true only inside test
            // fixtures, where it decorates a decision that never looks at it. A field with no producer and
            // no reader is indistinguishable from one that works.
            supportsConnectDestinationCharges: $this->marketplace instanceof MarketplaceRails,
            // `customer.subscription.trial_will_end` reaches StripeWebhookEventMapper, which turns it into
            // a TrialEnding event, and this driver registers SendTrialEndingNotice on it. The promise is
            // kept end to end, which is what lets the trial-warning command leave these customers alone.
            supportsProviderTrialNotice: true,
        );
    }
}
