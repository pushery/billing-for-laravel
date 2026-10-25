<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Shopify;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use Override;
use Pushery\Billing\Contracts\AttachesVerifiedVatIds;
use Pushery\Billing\Contracts\BillingDriver;
use Pushery\Billing\Contracts\CardPresentPayments;
use Pushery\Billing\Contracts\Checkout;
use Pushery\Billing\Contracts\CreditSync;
use Pushery\Billing\Contracts\CustomerDirectory;
use Pushery\Billing\Contracts\CustomerRegistry;
use Pushery\Billing\Contracts\HostedPortal;
use Pushery\Billing\Contracts\Invoices;
use Pushery\Billing\Contracts\LateFees;
use Pushery\Billing\Contracts\MeterInspector;
use Pushery\Billing\Contracts\OneTimeCharge;
use Pushery\Billing\Contracts\PairsReadersByTheirCode;
use Pushery\Billing\Contracts\PaymentCsp;
use Pushery\Billing\Contracts\PaymentMethods;
use Pushery\Billing\Contracts\ProrationStrategy;
use Pushery\Billing\Contracts\ReadsSubscriptionPayments;
use Pushery\Billing\Contracts\SeatBilling;
use Pushery\Billing\Contracts\StartsSubscriptions;
use Pushery\Billing\Contracts\SubmitsDisputeEvidence;
use Pushery\Billing\Contracts\SubscriptionActions;
use Pushery\Billing\Contracts\SubscriptionSync;
use Pushery\Billing\Contracts\UpcomingInvoice;
use Pushery\Billing\Contracts\UsageReporter;
use Pushery\Billing\Contracts\WebhookEventMapper;
use Pushery\Billing\Contracts\WebhookVerifier;
use Pushery\Billing\Drivers\NullCreditSync;
use Pushery\Billing\Drivers\NullCustomerRegistry;
use Pushery\Billing\Drivers\NullHostedPortal;
use Pushery\Billing\Drivers\NullInvoices;
use Pushery\Billing\Drivers\NullMeterInspector;
use Pushery\Billing\Drivers\NullSeatBilling;
use Pushery\Billing\Drivers\NullUpcomingInvoice;
use Pushery\Billing\Drivers\Stripe\StripeCustomerDirectory;
use Pushery\Billing\Dunning\NullLateFees;
use Pushery\Billing\Exceptions\ShopifyNotConfigured;
use Pushery\Billing\Proration\DelegatedProrationStrategy;
use Pushery\Billing\Support\BillingManager;
use Pushery\Billing\Support\RedirectPaymentCsp;
use Pushery\Billing\Support\WebhookSecretGuard;
use Pushery\Billing\Usage\NullUsageReporter;
use Pushery\Billing\Webhooks\Effects\SyncPlanFromSubscription;
use Throwable;

/**
 * Registers the Shopify driver, and rebinds the seams Stripe otherwise answers when it is the active one.
 *
 * `extend()` is unconditional, so a driver that exists by name can be asked for by name. The rebinds are
 * conditional: each replaces a package-wide contract, and every one of them would reach Stripe or a local engine
 * on an install that bills through Shopify. What Shopify does itself is answered by a null seam, since the app
 * has nothing to add: the invoice, the tax on it, dunning, seats, late fees, credit and the merchant's payment
 * methods are Shopify's. What it does not have at all is taken out of the container, so a host asking whether a
 * step exists gets the answer for the driver it runs.
 *
 * Nothing here talks to Shopify at registration time.
 */
final class ShopifyServiceProvider extends ServiceProvider
{
    /** Where the confirmation pages of a subscription and a purchase are served from. */
    public const array HOSTED_PAGE_HOSTS = ['admin.shopify.com', '*.myshopify.com'];

    #[Override]
    public function register(): void
    {
        $this->app->singleton(ShopifyDriver::class, static fn (Container $app): ShopifyDriver => new ShopifyDriver(
            new ShopifyPaymentRails,
            new ShopifyBillingEngine($app->make(ShopifyShops::class), $app->make(SyncPlanFromSubscription::class)),
        ));
    }

    public function boot(): void
    {
        $this->app->make(BillingManager::class)->extend('shopify', fn (): BillingDriver => $this->app->make(ShopifyDriver::class));

        if (! $this->isActive()) {
            return;
        }

        $this->app->bind(WebhookVerifier::class, ShopifyWebhookVerifier::class);
        $this->app->bind(WebhookEventMapper::class, ShopifyWebhookEventMapper::class);
        // A webhook's shop domain back to its owner. The shipped directory reads the configured customer model on the
        // configured column, the shop domain here, and is provider-neutral in what it does; bound here as the local
        // driver binds it, so this driver does not depend on another driver's provider having run.
        $this->app->bind(CustomerDirectory::class, StripeCustomerDirectory::class);
        $this->app->bind(Checkout::class, ShopifyCheckout::class);
        $this->app->bind(StartsSubscriptions::class, ShopifySubscriptionStarter::class);
        $this->app->bind(SubscriptionSync::class, ShopifySubscriptionSync::class);
        $this->app->bind(SubscriptionActions::class, ShopifySubscriptionActions::class);
        $this->app->bind(OneTimeCharge::class, ShopifyOneTimeCharge::class);
        $this->app->bind(ReadsSubscriptionPayments::class, ShopifySubscriptionPayments::class);
        $this->app->bind(PaymentMethods::class, ShopifyPaymentMethods::class);
        // Shopify prorates a replaced subscription itself and shows the price on the page where the merchant approves
        // it, so the plan screen previews no figure of its own and books nothing.
        $this->app->bind(ProrationStrategy::class, DelegatedProrationStrategy::class);
        // A confirmation page is opened by following the URL the checkout returned, from a form or a redirect.
        $this->app->bind(PaymentCsp::class, static fn (): PaymentCsp => new RedirectPaymentCsp(self::HOSTED_PAGE_HOSTS));

        // Shopify's own: the invoice and its tax, the next invoice, seats, late fees, credit and a portal.
        $this->app->bind(Invoices::class, NullInvoices::class);
        $this->app->bind(UpcomingInvoice::class, NullUpcomingInvoice::class);
        $this->app->bind(SeatBilling::class, NullSeatBilling::class);
        $this->app->bind(LateFees::class, NullLateFees::class);
        $this->app->bind(CreditSync::class, NullCreditSync::class);
        $this->app->bind(HostedPortal::class, NullHostedPortal::class);
        $this->app->bind(MeterInspector::class, NullMeterInspector::class);
        $this->app->bind(UsageReporter::class, NullUsageReporter::class);
        // Uninstalling the app is what removes a merchant from Shopify's billing, so there is nobody to delete.
        $this->app->bind(CustomerRegistry::class, NullCustomerRegistry::class);

        // No terminal, no dispute the app answers, no tax id the app attaches.
        $this->app->offsetUnset(CardPresentPayments::class);
        $this->app->offsetUnset(PairsReadersByTheirCode::class);
        $this->app->offsetUnset(SubmitsDisputeEvidence::class);
        $this->app->offsetUnset(AttachesVerifiedVatIds::class);

        $this->guardCredentials();
    }

    /** Whether Shopify is the driver this installation actually bills through. */
    private function isActive(): bool
    {
        $config = $this->app->make(Repository::class);

        return (bool) $config->get('billing.enabled', true)
            && $config->get('billing.default', 'stripe') === 'shopify';
    }

    /**
     * Refuse to boot a production install that selected Shopify without the client secret.
     *
     * Shopify signs every webhook with it, and the verifier refuses every webhook it cannot check. Without the
     * secret nothing fails at boot; every subscription change would be refused, one webhook after another, and the
     * merchants would keep the plans they had.
     */
    private function guardCredentials(): void
    {
        $secret = $this->app->make(Repository::class)->get('billing.shopify.client_secret');

        $this->app->make(WebhookSecretGuard::class)->ensureCredential(
            $this->app->environment(),
            is_string($secret) ? $secret : null,
            static fn (): Throwable => ShopifyNotConfigured::missingClientSecret(),
        );
    }
}
