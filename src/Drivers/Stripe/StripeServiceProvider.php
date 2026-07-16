<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Override;
use Pushery\Billing\Contracts\Checkout;
use Pushery\Billing\Contracts\CreditSync;
use Pushery\Billing\Contracts\CustomerDirectory;
use Pushery\Billing\Contracts\CustomerRegistry;
use Pushery\Billing\Contracts\HostedPortal;
use Pushery\Billing\Contracts\Invoices;
use Pushery\Billing\Contracts\LateFees;
use Pushery\Billing\Contracts\MeterInspector;
use Pushery\Billing\Contracts\OneTimeCharge;
use Pushery\Billing\Contracts\PaymentCsp;
use Pushery\Billing\Contracts\PaymentMethods;
use Pushery\Billing\Contracts\ProrationStrategy;
use Pushery\Billing\Contracts\SeatBilling;
use Pushery\Billing\Contracts\SubscriptionActions;
use Pushery\Billing\Contracts\SubscriptionSync;
use Pushery\Billing\Contracts\UpcomingInvoice;
use Pushery\Billing\Contracts\UsageReporter;
use Pushery\Billing\Contracts\WebhookEventMapper;
use Pushery\Billing\Contracts\WebhookVerifier;
use Pushery\Billing\Drivers\NullCustomerRegistry;
use Pushery\Billing\Events\AddonPurchased;
use Pushery\Billing\Events\AddonRefunded;
use Pushery\Billing\Events\InvoiceCredited;
use Pushery\Billing\Events\InvoiceFinalized;
use Pushery\Billing\Events\InvoiceUpcoming;
use Pushery\Billing\Events\MandateRevoked;
use Pushery\Billing\Events\PaymentActionRequired;
use Pushery\Billing\Events\PaymentFailed;
use Pushery\Billing\Events\PaymentSucceeded;
use Pushery\Billing\Events\SubscriptionStateChanged;
use Pushery\Billing\Events\TrialEnding;
use Pushery\Billing\Support\BillingManager;
use Pushery\Billing\Support\WebhookSecretGuard;
use Pushery\Billing\Webhooks\Effects\CreditAddonPurchase;
use Pushery\Billing\Webhooks\Effects\FlushUpcomingUsage;
use Pushery\Billing\Webhooks\Effects\PersistCreditNote;
use Pushery\Billing\Webhooks\Effects\PersistInvoice;
use Pushery\Billing\Webhooks\Effects\ReverseAddonPurchase;
use Pushery\Billing\Webhooks\Effects\RevokeMandate;
use Pushery\Billing\Webhooks\Effects\SendDunningNotice;
use Pushery\Billing\Webhooks\Effects\SendPaymentActionRequiredNotice;
use Pushery\Billing\Webhooks\Effects\SendPaymentReceipt;
use Pushery\Billing\Webhooks\Effects\SendSubscriptionActivatedNotice;
use Pushery\Billing\Webhooks\Effects\SendSubscriptionCanceledNotice;
use Pushery\Billing\Webhooks\Effects\SendTrialEndingNotice;
use Pushery\Billing\Webhooks\Effects\SyncPlanFromSubscription;
use Pushery\Billing\Webhooks\WebhookEffectRegistry;
use Stripe\StripeClient;

/**
 * Wires the Stripe driver into the container: a configured Stripe SDK client, the driver factory the
 * BillingManager resolves for the "stripe" name, and the concrete implementations of the neutral
 * account-hub and webhook contracts. Registered by the package's service provider; the future
 * Mollie/Adyen drivers ship their own providers that rebind these when they are the active driver.
 */
final class StripeServiceProvider extends ServiceProvider
{
    /**
     * The Stripe API version the package is written and TESTED against. The default a consuming app runs
     * on unless it sets billing.stripe.api_version. Moving this is a deliberate act: bump it, run the
     * live-Stripe suite against the new version, and ship — never let a dependency update move it instead.
     */
    public const string STRIPE_API_VERSION = '2025-08-27.basil';

    #[Override]
    public function register(): void
    {
        $this->app->bind(StripeClient::class, fn (Application $app): StripeClient => new StripeClient([
            'api_key' => $this->apiKey($app),
            'stripe_version' => $this->apiVersion($app),
        ]));

        $this->app->bind(CustomerDirectory::class, StripeCustomerDirectory::class);
        $this->app->bind(HostedPortal::class, StripeHostedPortal::class);
        $this->app->bind(PaymentMethods::class, StripePaymentMethods::class);
        $this->app->bind(Invoices::class, StripeInvoices::class);
        $this->app->bind(UpcomingInvoice::class, StripeUpcomingInvoice::class);
        $this->app->bind(SubscriptionActions::class, StripeSubscriptionActions::class);
        $this->app->bind(OneTimeCharge::class, StripeOneTimeCharge::class);
        $this->app->bind(Checkout::class, StripeCheckout::class);
        $this->app->bind(SubscriptionSync::class, StripeSubscriptionSync::class);
        // Stripe books proration on its own side, but the account hub still previews the cost of a
        // swap before committing — the Stripe strategy does that via create_preview. The generic
        // DelegatedProrationStrategy remains the package default for drivers with no preview; the
        // credit-balance drivers replace it with a ProrationCalculator-backed one.
        $this->app->bind(ProrationStrategy::class, StripeProrationStrategy::class);
        $this->app->bind(WebhookVerifier::class, StripeWebhookVerifier::class);
        $this->app->bind(WebhookEventMapper::class, StripeWebhookEventMapper::class);
        $this->app->bind(PaymentCsp::class, StripePaymentCsp::class);
        $this->app->bind(SeatBilling::class, StripeSeatBilling::class);
        // Usage is billed by Stripe's own meters; the package hands it the units it recorded.
        $this->app->bind(UsageReporter::class, StripeUsageReporter::class);
        // billing:meters:check verifies the configured meters against Stripe's active meters.
        $this->app->bind(MeterInspector::class, StripeMeterInspector::class);
        // Dunning late fees ride on the next Stripe invoice as a pending invoice item.
        $this->app->bind(LateFees::class, StripeLateFees::class);
        // Package credit is mirrored onto the Stripe customer balance so it reduces the next invoice.
        $this->app->bind(CreditSync::class, StripeCreditSync::class);

        // Whether erasing an owner also DELETES their customer at Stripe. Off unless the app asks: it is
        // irreversible, and it cancels that customer's live subscriptions at the provider.
        $this->app->bind(CustomerRegistry::class, function (Application $app): CustomerRegistry {
            $forget = $app->make(Repository::class)->get('billing.erasure.forget_customer', false);

            return (bool) $forget
                ? $app->make(StripeCustomerRegistry::class)
                : new NullCustomerRegistry;
        });
    }

    public function boot(): void
    {
        $this->app->make(BillingManager::class)->extend(
            'stripe',
            fn (): StripeDriver => new StripeDriver(new StripePaymentRails($this->app->make(StripeClient::class))),
        );

        $this->registerDefaultEffects($this->app->make(WebhookEffectRegistry::class));
        $this->guardWebhookSecret($this->app);
    }

    /**
     * Wire the package's default webhook effects onto the neutral effect bus so a Stripe app syncs plans,
     * credits add-ons, reverses refunded ones and sends dunning out of the box. Effects are registered by
     * CLASS, so each is dispatched as its own queued job — isolated, retried and recorded on its own. A
     * consuming app registers further effects on the same registry.
     */
    private function registerDefaultEffects(WebhookEffectRegistry $registry): void
    {
        $registry->on(SubscriptionStateChanged::class, SyncPlanFromSubscription::class);
        // A cancellation is only worth telling the customer about together with the date their access ends,
        // so this one keys on the grace state; it runs beside the plan sync, isolated in its own job.
        $registry->on(SubscriptionStateChanged::class, SendSubscriptionCanceledNotice::class);
        // …and its counterpart: the subscription is live. Deduped once per subscription, so recovering from
        // past_due back to active does not welcome the customer a second time.
        $registry->on(SubscriptionStateChanged::class, SendSubscriptionActivatedNotice::class);
        $registry->on(AddonPurchased::class, CreditAddonPurchase::class);
        $registry->on(AddonRefunded::class, ReverseAddonPurchase::class);
        $registry->on(PaymentFailed::class, SendDunningNotice::class);
        // A payment the bank held for 3-D Secure: nudge the customer to confirm, or the subscription sits
        // incomplete while they think they subscribed.
        $registry->on(PaymentActionRequired::class, SendPaymentActionRequiredNotice::class);
        // The other half of the money conversation: the package told the customer when their money did NOT
        // move; this tells them when it did.
        $registry->on(PaymentSucceeded::class, SendPaymentReceipt::class);
        $registry->on(InvoiceFinalized::class, PersistInvoice::class);
        $registry->on(InvoiceCredited::class, PersistCreditNote::class);
        $registry->on(MandateRevoked::class, RevokeMandate::class);
        $registry->on(InvoiceUpcoming::class, FlushUpcomingUsage::class);
        $registry->on(TrialEnding::class, SendTrialEndingNotice::class);
    }

    /** In production with Stripe active, refuse to boot without a webhook signing secret. */
    private function guardWebhookSecret(Application $app): void
    {
        $config = $app->make(Repository::class);

        $stripeActive = (bool) $config->get('billing.enabled', true)
            && $config->get('billing.default', 'stripe') === 'stripe';

        if ($stripeActive) {
            $secret = $config->get('cashier.webhook.secret');

            $app->make(WebhookSecretGuard::class)->ensureConfigured(
                'stripe',
                $app->environment(),
                is_string($secret) && $secret !== '' ? $secret : null,
            );
        }
    }

    /** The Stripe secret key, or null (never the empty string — the SDK rejects that at construction). */
    private function apiKey(Application $app): ?string
    {
        $secret = $app->make(Repository::class)->get('cashier.secret');

        return is_string($secret) && $secret !== '' ? $secret : null;
    }

    /**
     * The Stripe API version every call and webhook runs against — pinned BY THE PACKAGE, not inherited.
     *
     * Stripe versions its API by date, and the shape of a webhook payload follows the version. If the
     * version tracked whatever the installed SDK happens to ship (the SDK's own CURRENT constant, which
     * every SDK release rewrites), a routine `composer update` — in the
     * CONSUMER's app, not ours — would silently move the version our mapper parses against. And the failure
     * is silent, not loud: the mapper reads each field defensively and a removed field makes a real billing
     * event quietly not fire. So the version is ours to choose and hold, and a change to it is a change a
     * human makes here, against the live-Stripe suite — never a side effect of updating a dependency.
     */
    private function apiVersion(Application $app): string
    {
        $version = $app->make(Repository::class)->get('billing.stripe.api_version');

        return is_string($version) && $version !== '' ? $version : self::STRIPE_API_VERSION;
    }
}
