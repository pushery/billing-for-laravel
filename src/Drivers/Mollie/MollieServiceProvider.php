<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Mollie;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Mollie\Api\MollieApiClient;
use Override;
use Pushery\Billing\Contracts\BillingDriver;
use Pushery\Billing\Contracts\CustomerDirectory;
use Pushery\Billing\Contracts\EnsuresProviderCustomer;
use Pushery\Billing\Contracts\EstablishesMandateByRedirect;
use Pushery\Billing\Contracts\Invoices;
use Pushery\Billing\Contracts\PaymentCsp;
use Pushery\Billing\Contracts\PaymentMethods;
use Pushery\Billing\Contracts\PlanCatalog;
use Pushery\Billing\Contracts\ProrationStrategy;
use Pushery\Billing\Contracts\StartsSubscriptions;
use Pushery\Billing\Contracts\SubscriptionActions;
use Pushery\Billing\Contracts\TierCatalog;
use Pushery\Billing\Contracts\WebhookEventMapper;
use Pushery\Billing\Contracts\WebhookVerifier;
use Pushery\Billing\Discounts\CouponRedeemer;
use Pushery\Billing\Discounts\CycleCouponApplier;
use Pushery\Billing\Drivers\Stripe\StripeCustomerDirectory;
use Pushery\Billing\Dunning\ConfigDunningLadder;
use Pushery\Billing\Events\MandateEstablished;
use Pushery\Billing\Events\PaymentFailed;
use Pushery\Billing\Events\PaymentSucceeded;
use Pushery\Billing\Exceptions\MollieNotConfigured;
use Pushery\Billing\Invoicing\LocalInvoices;
use Pushery\Billing\Invoicing\OrderInvoiceIssuer;
use Pushery\Billing\Proration\CreditBalanceProrationStrategy;
use Pushery\Billing\Support\BillingManager;
use Pushery\Billing\Support\CheckoutUrls;
use Pushery\Billing\Support\CreditLedger;
use Pushery\Billing\Support\CycleItemPricer;
use Pushery\Billing\Support\LocalBillingEngine;
use Pushery\Billing\Support\LocalSubscriptionActions;
use Pushery\Billing\Support\LocalSubscriptionStarter;
use Pushery\Billing\Support\OrderItemPreprocessorChain;
use Pushery\Billing\Support\WebhookSecretGuard;
use Pushery\Billing\Trials\TrialPolicy;
use Pushery\Billing\Trials\Trials;
use Pushery\Billing\Webhooks\Effects\FailCycleOnPayment;
use Pushery\Billing\Webhooks\Effects\SettleCycleOnPayment;
use Pushery\Billing\Webhooks\Effects\StartSubscriptionOnMandate;
use Pushery\Billing\Webhooks\WebhookEffectRegistry;
use Throwable;

/**
 * Registers the Mollie driver, and rebinds the webhook and payment-method seams when it is the active one.
 *
 * The split matters. `extend()` is unconditional: registering a driver by name costs nothing and must not
 * depend on configuration, or an install resolving `driver('mollie')` explicitly — a marketplace running
 * two providers, a migration in progress — would be told the driver does not exist. The REBINDS below are
 * conditional, because they replace package-wide contracts that Stripe otherwise answers.
 *
 * Nothing here constructs an API client at registration time. The client is a singleton resolved on first
 * use, so an install that has the driver available but does not use it never reads a key, and a test suite
 * never builds an HTTP client it will not call.
 */
final class MollieServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        // A singleton because each construction reads the key and builds an HTTP client — waste that only
        // shows up under load, on the scheduled run that charges everybody at once.
        $this->app->singleton(MollieApiClient::class, static fn (): MollieApiClient => new MollieClientFactory()->make());

        $this->app->singleton(MolliePaymentRails::class, static fn (Container $app): MolliePaymentRails => new MolliePaymentRails(
            $app->make(MollieApiClient::class),
            self::webhookUrl($app->make(Repository::class)),
        ));
        $this->app->singleton(MolliePaymentMethods::class);

        $this->app->singleton(MollieDriver::class, static fn (Container $app): MollieDriver => new MollieDriver(
            $app->make(MolliePaymentRails::class),
            new LocalBillingEngine(
                'mollie',
                $app->make(MolliePaymentRails::class),
                $app->make(CreditLedger::class),
                $app->make(Repository::class),
                $app->make(OrderItemPreprocessorChain::class),
                $app->make(CycleItemPricer::class),
                $app->make(CycleCouponApplier::class),
                $app->make(OrderInvoiceIssuer::class),
                $app->make(ConfigDunningLadder::class),
                MollieCapabilities::make(),
            ),
        ));
    }

    public function boot(): void
    {
        $manager = $this->app->make(BillingManager::class);

        // Unconditional: a driver that exists by name can be asked for by name.
        $manager->extend('mollie', fn (): BillingDriver => $this->app->make(MollieDriver::class));

        if (! $this->isActive()) {
            return;
        }

        $this->app->bind(WebhookVerifier::class, MollieWebhookVerifier::class);
        $this->app->bind(WebhookEventMapper::class, MollieWebhookEventMapper::class);
        $this->app->bind(Invoices::class, LocalInvoices::class);
        // Without this the local driver fell back to NullSubscriptionActions, whose methods are empty:
        // canceling did nothing, swapping did nothing, and neither said so.
        $this->app->bind(SubscriptionActions::class, LocalSubscriptionActions::class);
        // Without this the local driver kept Stripe's strategy, whose applySwap() is a deliberate no-op
        // because Stripe books the proration itself. Mollie does not — so a plan change credited the
        // subscriber nothing for the time they had already paid for, and no state anywhere looked wrong.
        $this->app->bind(ProrationStrategy::class, CreditBalanceProrationStrategy::class);
        $this->app->bind(PaymentMethods::class, MolliePaymentMethods::class);
        $this->app->bind(PaymentCsp::class, MolliePaymentCsp::class);
        // Turning an owner into a provider customer, which nothing could do before: the reference was only
        // ever read off an existing mandate, so it answered null for exactly the person a subscribe flow is
        // for — somebody with no payment method yet.
        $this->app->bind(EnsuresProviderCustomer::class, MollieCustomers::class);
        // The redirect half of establishing a mandate. Bound separately from `PaymentRails` even though one
        // class satisfies both, because they are different questions: a driver may take payments without
        // being able to establish a mandate by redirect, and a consumer replacing one must not silently
        // replace the other.
        $this->app->bind(EstablishesMandateByRedirect::class, MolliePaymentRails::class);
        // Resolving a customer reference on a webhook back to an owner. The shipped directory reads the
        // configured customer model on the configured column and is provider-neutral in what it does; it is
        // bound here as well so the local driver does not depend on another driver's provider having run.
        $this->app->bind(CustomerDirectory::class, StripeCustomerDirectory::class);
        $this->app->bind(StartsSubscriptions::class, fn (): LocalSubscriptionStarter => new LocalSubscriptionStarter(
            'mollie',
            $this->app->make(TierCatalog::class),
            $this->app->make(PlanCatalog::class),
            $this->app->make(TrialPolicy::class),
            $this->app->make(Trials::class),
            $this->app->make(EnsuresProviderCustomer::class),
            $this->app->make(EstablishesMandateByRedirect::class),
            $this->app->make(Repository::class),
            $this->app->make(CheckoutUrls::class),
            $this->app->make(CouponRedeemer::class),
        ));

        // The webhook that finishes what the redirect started. Registered here rather than with the shipped
        // default effects because it only means anything for a driver whose mandates arrive this way.
        //
        // And the two that finish what the CYCLE started. A charge this driver makes is not always answered
        // while the sweep is still running — a SEPA direct debit is accepted at once and settles days later
        // — so the cycle is held open and closed from here, in whichever direction the money went. Both are
        // registered for the same reason as the one above: they mean something only where the package runs
        // the billing cycle itself, and a provider that runs its own answers through its own events.
        $registry = $this->app->make(WebhookEffectRegistry::class);

        $registry->on(MandateEstablished::class, StartSubscriptionOnMandate::class);
        $registry->on(PaymentSucceeded::class, SettleCycleOnPayment::class);
        $registry->on(PaymentFailed::class, FailCycleOnPayment::class);

        $this->guardCredentials();
    }

    /**
     * The absolute URL Mollie returns customers to and posts its status pings at.
     *
     * Configuration rather than a generated route URL, because neither of the two things `route()` needs is
     * reliably there: a package cannot know the public host, and the scheduled billing run creates payments
     * from the CLI, where there is no request to take a host from. The fallback is right for a single-host
     * install and wrong behind a development tunnel — which is exactly why the setting exists.
     */
    private static function webhookUrl(Repository $config): string
    {
        $configured = $config->get('billing.mollie.webhook_url');

        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        $base = $config->get('app.url');
        $path = $config->get('billing.webhook_path', 'billing/webhook');

        return rtrim(is_string($base) ? $base : '', '/').'/'.ltrim(is_string($path) ? $path : 'billing/webhook', '/');
    }

    /** Whether Mollie is the driver this installation actually bills through. */
    private function isActive(): bool
    {
        $config = $this->app->make(Repository::class);

        return (bool) $config->get('billing.enabled', true)
            && $config->get('billing.default', 'stripe') === 'mollie';
    }

    /**
     * Refuse to boot a production install that selected Mollie and did not configure its key.
     *
     * The failure this replaces is the silent one: without a key nothing goes wrong at boot. It goes wrong
     * at the first charge — inside a scheduled run, hours later, against a real subscriber, with the error
     * in a log rather than in front of whoever deployed.
     *
     * A webhook signing secret is deliberately NOT required. Mollie's legacy generation carries no
     * signature at all, so demanding one would refuse to boot every install not yet on the next
     * generation; the verifier treats a configured secret as the switch instead.
     */
    private function guardCredentials(): void
    {
        $config = $this->app->make(Repository::class);
        $key = $config->get('billing.mollie.api_key');

        $guard = $this->app->make(WebhookSecretGuard::class);

        $guard->ensureCredential(
            $this->app->environment(),
            is_string($key) ? $key : null,
            static fn (): Throwable => MollieNotConfigured::missingApiKey(),
        );

        $secret = $config->get('billing.mollie.webhook_secret');

        $guard->warnWhenAbsent(
            $this->app->environment(),
            is_string($secret) ? $secret : null,
            static fn (): null => Log::warning(
                'billing: Mollie is configured without a webhook signing secret, so this endpoint accepts '.
                'unsigned pings. That is correct for an account on Mollie\'s legacy webhooks and a real gap '.
                'on an account using the next generation, which signs every request — set '.
                'billing.mollie.webhook_secret if the Mollie dashboard shows signing enabled.',
            ),
        );
    }
}
