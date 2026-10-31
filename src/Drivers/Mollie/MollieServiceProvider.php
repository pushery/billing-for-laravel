<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Mollie;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Mollie\Api\MollieApiClient;
use Override;
use Pushery\Billing\Contracts\AddonCatalog;
use Pushery\Billing\Contracts\AttachesVerifiedVatIds;
use Pushery\Billing\Contracts\BillingDriver;
use Pushery\Billing\Contracts\CanTransactMoney;
use Pushery\Billing\Contracts\CardPresentPayments;
use Pushery\Billing\Contracts\CollectsDirectDebits;
use Pushery\Billing\Contracts\CreditSync;
use Pushery\Billing\Contracts\CustomerDirectory;
use Pushery\Billing\Contracts\CustomerRegistry;
use Pushery\Billing\Contracts\EnabledPaymentMethods;
use Pushery\Billing\Contracts\EnsuresProviderCustomer;
use Pushery\Billing\Contracts\EstablishesMandateByRedirect;
use Pushery\Billing\Contracts\HostedPortal;
use Pushery\Billing\Contracts\Invoices;
use Pushery\Billing\Contracts\IssuesReaderPairingCodes;
use Pushery\Billing\Contracts\KeepsAFallbackMethod;
use Pushery\Billing\Contracts\LateFees;
use Pushery\Billing\Contracts\MeterInspector;
use Pushery\Billing\Contracts\OneTimeCharge;
use Pushery\Billing\Contracts\PairsReadersByTheirCode;
use Pushery\Billing\Contracts\PaymentCsp;
use Pushery\Billing\Contracts\PaymentMethods;
use Pushery\Billing\Contracts\PaysOutstandingInvoices;
use Pushery\Billing\Contracts\PlanCatalog;
use Pushery\Billing\Contracts\ProrationStrategy;
use Pushery\Billing\Contracts\ReadsSubscriptionPayments;
use Pushery\Billing\Contracts\ReportsMoneyBackOutcomes;
use Pushery\Billing\Contracts\SeatBilling;
use Pushery\Billing\Contracts\StartsSubscriptions;
use Pushery\Billing\Contracts\SubmitsDisputeEvidence;
use Pushery\Billing\Contracts\SubscriptionActions;
use Pushery\Billing\Contracts\TierCatalog;
use Pushery\Billing\Contracts\UpcomingInvoice;
use Pushery\Billing\Contracts\UsageReporter;
use Pushery\Billing\Contracts\WebhookEventMapper;
use Pushery\Billing\Contracts\WebhookVerifier;
use Pushery\Billing\DirectDebit\PreNotifications;
use Pushery\Billing\Discounts\CouponRedeemer;
use Pushery\Billing\Discounts\CycleCouponApplier;
use Pushery\Billing\Drivers\NullBillingEngine;
use Pushery\Billing\Drivers\NullCreditSync;
use Pushery\Billing\Drivers\NullCustomerRegistry;
use Pushery\Billing\Drivers\NullHostedPortal;
use Pushery\Billing\Drivers\NullMeterInspector;
use Pushery\Billing\Drivers\Stripe\StripeCustomerDirectory;
use Pushery\Billing\Dunning\ConfigDunningLadder;
use Pushery\Billing\Dunning\LocalLateFees;
use Pushery\Billing\Events\MandateEstablished;
use Pushery\Billing\Events\PaymentFailed;
use Pushery\Billing\Events\PaymentSucceeded;
use Pushery\Billing\Exceptions\MollieNotConfigured;
use Pushery\Billing\Invoicing\LocalInvoices;
use Pushery\Billing\Invoicing\OrderInvoiceIssuer;
use Pushery\Billing\Invoicing\ProrationCreditCorrectionIssuer;
use Pushery\Billing\Marketplace\MarketplaceSaleContext;
use Pushery\Billing\Proration\ArrearsProrationStrategy;
use Pushery\Billing\Proration\InAdvanceProrationStrategy;
use Pushery\Billing\Proration\LocalProrationStrategy;
use Pushery\Billing\Proration\RestOfPeriod;
use Pushery\Billing\Support\BillingEventLog;
use Pushery\Billing\Support\BillingManager;
use Pushery\Billing\Support\CheckoutUrls;
use Pushery\Billing\Support\CreditLedger;
use Pushery\Billing\Support\CycleItemPricer;
use Pushery\Billing\Support\LocalBillingEngine;
use Pushery\Billing\Support\LocalSeatBilling;
use Pushery\Billing\Support\LocalSubscriptionActions;
use Pushery\Billing\Support\LocalSubscriptionPayments;
use Pushery\Billing\Support\LocalSubscriptionStarter;
use Pushery\Billing\Support\LocalUpcomingInvoice;
use Pushery\Billing\Support\OrderItemPreprocessorChain;
use Pushery\Billing\Support\RedirectPaymentCsp;
use Pushery\Billing\Support\WebhookSecretGuard;
use Pushery\Billing\Tax\SaleTaxDecision;
use Pushery\Billing\Trials\TrialPolicy;
use Pushery\Billing\Trials\Trials;
use Pushery\Billing\Usage\NullUsageReporter;
use Pushery\Billing\ValueObjects\PaymentAccount;
use Pushery\Billing\Webhooks\AccountWebhooks;
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
 * two providers, a migration in progress — would be told the driver does not exist. The rebinds below are
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
            $app->make(CheckoutUrls::class),
        ));
        $this->app->singleton(MolliePaymentMethods::class);
        $this->app->bind(MollieCardPresentPayments::class, static fn (Container $app): MollieCardPresentPayments => new MollieCardPresentPayments(
            $app->make(MollieApiClient::class),
            $app->make(SaleTaxDecision::class),
            self::webhookUrl($app->make(Repository::class)),
            self::terminalCountries($app->make(Repository::class)),
        ));

        $this->app->singleton(MollieDriver::class, static fn (Container $app): MollieDriver => new MollieDriver(
            $app->make(MolliePaymentRails::class),
            self::engine($app),
        ));
    }

    public function boot(): void
    {
        $manager = $this->app->make(BillingManager::class);

        // Unconditional: a driver that exists by name can be asked for by name.
        $manager->extend('mollie', fn (): BillingDriver => $this->app->make(MollieDriver::class));

        // A payment account builds a driver of its own: a client with the account's credential and test mode, its
        // profile on every payment it creates, a webhook address that names the account, and the null engine.
        // Unconditional as well: an install that runs Stripe for itself may still take payments into Mollie
        // accounts of its shops.
        $manager->extendAccounts('mollie', fn (PaymentAccount $account): BillingDriver => new MollieDriver(
            new MolliePaymentRails(
                new MollieClientFactory()->forAccount($account),
                $this->accountWebhookUrl($this->app->make(Repository::class), $account),
                $this->app->make(CheckoutUrls::class),
                $account->profileId,
            ),
            new NullBillingEngine,
        ));

        // Its webhooks arrive on the account's own address. The verifier checks the account's signing secret, and the
        // mapper reads a classic ping back with the account's client, which is also what proves the payment is the
        // account's: another account's payment id is not found with this credential. It reports AccountPaymentChanged
        // and nothing the install's own effects would read as theirs.
        $manager->extendAccountWebhooks('mollie', static fn (PaymentAccount $account): AccountWebhooks => new AccountWebhooks(
            new MollieWebhookVerifier($account->webhookSecret === null ? [] : [$account->webhookSecret]),
            new MollieAccountWebhookEventMapper(new MollieClientFactory()->forAccount($account), $account->key),
        ));

        // A shop page that mounts the card fields or redirects to the checkout for an account adds these sources to
        // its own policy. They are the same for every account.
        $manager->extendAccountCsp('mollie', static fn (): PaymentCsp => new MolliePaymentCsp);
        $manager->extendAccountRedirectCsp('mollie', static fn (): PaymentCsp => new RedirectPaymentCsp(MollieHostedPages::HOSTS));

        // The payment methods an account has turned on, read with its client and on its profile.
        $manager->extendAccountPaymentMethods('mollie', static fn (PaymentAccount $account): EnabledPaymentMethods => new MollieEnabledPaymentMethods(
            new MollieClientFactory()->forAccount($account),
            $account->profileId,
        ));

        if (! $this->isActive()) {
            return;
        }

        $this->app->bind(WebhookVerifier::class, MollieWebhookVerifier::class);
        $this->app->bind(WebhookEventMapper::class, MollieWebhookEventMapper::class);
        $this->app->bind(Invoices::class, LocalInvoices::class);
        // Without this the local driver fell back to NullSubscriptionActions, whose methods are empty:
        // canceling did nothing, swapping did nothing, and neither said so.
        $this->app->bind(SubscriptionActions::class, LocalSubscriptionActions::class);
        // Rebound because the Stripe provider binds its own reader unconditionally, and that one would ask Stripe
        // about a subscription it never saw. The local reader answers from the order that paid the period where it
        // was collected in advance, and says that nothing was paid where it is collected at its end.
        $this->app->bind(ReadsSubscriptionPayments::class, LocalSubscriptionPayments::class);
        // Without this the local driver kept Stripe's strategy, whose applySwap() is a deliberate no-op
        // because Stripe books the proration itself. Chosen per subscription by when it is collected: in advance,
        // the rest of the paid period is charged or credited at once; in arrears, a plan change is priced into the
        // bill that closes the period, each tier for the days it held.
        $this->app->bind(ProrationStrategy::class, static fn (Container $app): ProrationStrategy => new LocalProrationStrategy(
            $app->make(ArrearsProrationStrategy::class),
            new InAdvanceProrationStrategy(self::restOfPeriod($app), $app->make(PlanCatalog::class), $app->make(BillingEventLog::class)),
        ));
        $this->app->bind(PaymentMethods::class, MolliePaymentMethods::class);
        // A fallback is charged by the engine below, so only this driver can keep the promise and only here is it bound.
        $this->app->bind(KeepsAFallbackMethod::class, MolliePaymentMethods::class);
        $this->app->bind(PaymentCsp::class, MolliePaymentCsp::class);
        // Payment in person, on Mollie's own terminals. Rebound because the Stripe provider binds its reader path
        // unconditionally, and on this driver that one would ask Stripe about a reader it never saw. Mollie pairs by
        // a code it issues, so Stripe's pairing is taken out of the container: a host asking whether it is bound
        // gets the answer for the driver it runs.
        $this->app->bind(CardPresentPayments::class, MollieCardPresentPayments::class);
        $this->app->bind(IssuesReaderPairingCodes::class, MollieCardPresentPayments::class);
        $this->app->offsetUnset(PairsReadersByTheirCode::class);
        // Mollie takes no dispute evidence through its API. The Stripe provider binds its own answer unconditionally,
        // and the documentation tells a host to ask the container whether the step exists, so on this driver the
        // binding is taken out rather than left to answer yes and reach Stripe without a key.
        $this->app->offsetUnset(SubmitsDisputeEvidence::class);
        // Nor does a Mollie customer carry tax ids, so there is nowhere to attach a proven VAT number.
        $this->app->offsetUnset(AttachesVerifiedVatIds::class);
        // A Mollie subscription is billed by the package's own engine, and Mollie holds no invoice to pay on request.
        $this->app->offsetUnset(PaysOutstandingInvoices::class);
        // What became of a refund or a chargeback where Mollie's webhook does not say: a refund canceled before it was
        // paid out, one that failed, a chargeback the bank reversed. `billing:money-back:reconcile` reads them back daily.
        $this->app->bind(ReportsMoneyBackOutcomes::class, MollieMoneyBackOutcomes::class);
        // The paths that reach the provider on Stripe, answered for this engine. The Stripe provider binds its own
        // unconditionally, and each would ask Stripe about a customer whose reference Mollie issued. Credit stays in
        // the package's ledger, which this engine spends at the cycle; usage is rated from the package's own
        // counters; there are no provider meters to inspect and no hosted portal to open.
        $this->app->bind(CreditSync::class, NullCreditSync::class);
        $this->app->bind(UsageReporter::class, NullUsageReporter::class);
        $this->app->bind(MeterInspector::class, NullMeterInspector::class);
        $this->app->bind(HostedPortal::class, NullHostedPortal::class);
        // The next invoice, previewed from the engine that will bill it. It is built the way the driver's engine is,
        // so the preview and the order that follows are priced by the same steps.
        $this->app->bind(UpcomingInvoice::class, static fn (Container $app): UpcomingInvoice => new LocalUpcomingInvoice(self::engine($app), 'mollie'));
        // Seats live on the local subscription. In arrears the cycle bills each quantity for the days it held; in
        // advance a change squares the rest of the paid period at once.
        $this->app->bind(SeatBilling::class, static fn (Container $app): SeatBilling => new LocalSeatBilling('mollie', self::restOfPeriod($app), $app->make(PlanCatalog::class)));
        // A late fee is an open order of its own here, collected once a cycle of the owner has been paid. It cannot
        // be a line of the cycle: its document is outside the scope of VAT, and that category shares a document
        // with no other.
        $this->app->bind(LateFees::class, static fn (): LateFees => new LocalLateFees('mollie'));
        // An add-on is bought on Mollie's hosted checkout, as a `oneoff` payment for the catalog's price. The order it
        // writes is what the invoice is raised from once Mollie confirms the payment, because Mollie issues none.
        $this->app->bind(OneTimeCharge::class, static fn (Container $app): OneTimeCharge => new MollieOneTimeCharge(
            $app->make(MollieApiClient::class),
            $app->make(AddonCatalog::class),
            $app->make(MollieCustomers::class),
            $app->make(CanTransactMoney::class),
            $app->make(MarketplaceSaleContext::class),
            $app->make(CheckoutUrls::class),
            self::webhookUrl($app->make(Repository::class)),
        ));
        // Erasing an owner deletes their customer at the provider only where the app asked for it, as on Stripe.
        $this->app->bind(CustomerRegistry::class, static fn (Container $app): CustomerRegistry => (bool) $app->make(Repository::class)->get('billing.erasure.forget_customer', false)
            ? $app->make(MollieCustomers::class)
            : new NullCustomerRegistry);
        // Turning an owner into a provider customer, which nothing could do before: the reference was only
        // ever read off an existing mandate, so it answered null for exactly the person a subscribe flow is
        // for — somebody with no payment method yet.
        $this->app->bind(EnsuresProviderCustomer::class, MollieCustomers::class);
        // The redirect half of establishing a mandate. Bound separately from `PaymentRails` even though one
        // class satisfies both, because they are different questions: a driver may take payments without
        // being able to establish a mandate by redirect, and a consumer replacing one must not silently
        // replace the other.
        $this->app->bind(EstablishesMandateByRedirect::class, MolliePaymentRails::class);
        // The direct debit mandates an account holder gives on the application's own form, and whether a charge against a
        // stored mandate is drawn as a direct debit, which the engine announces first. Bound on its own for the same reason
        // as the redirect half above.
        $this->app->bind(CollectsDirectDebits::class, MolliePaymentRails::class);
        // Resolving a customer reference on a webhook back to an owner. The shipped directory reads the
        // configured customer model on the configured column and is provider-neutral in what it does; it is
        // bound here as well so the local driver does not depend on another driver's provider having run.
        $this->app->bind(CustomerDirectory::class, StripeCustomerDirectory::class);
        $this->app->bind(StartsSubscriptions::class, static fn (Container $app): LocalSubscriptionStarter => new LocalSubscriptionStarter(
            'mollie',
            $app->make(TierCatalog::class),
            $app->make(PlanCatalog::class),
            $app->make(TrialPolicy::class),
            $app->make(Trials::class),
            $app->make(EnsuresProviderCustomer::class),
            $app->make(EstablishesMandateByRedirect::class),
            $app->make(Repository::class),
            $app->make(CheckoutUrls::class),
            $app->make(CouponRedeemer::class),
        ));

        // The webhook that finishes what the redirect started. Registered here rather than with the shipped
        // default effects because it only means anything for a driver whose mandates arrive this way.
        //
        // And the two that finish what the cycle started. A charge this driver makes is not always answered
        // while the sweep is still running — a SEPA direct debit is accepted at once and settles days later
        // — so the cycle is held open and closed from here, in whichever direction the money went. Both are
        // registered for the same reason as the one above: they mean something only where the package runs
        // the billing cycle itself, and a provider that runs its own answers through its own events.
        //
        // Payments-only mode runs no billing cycle, so it registers none of the three.
        if (! $manager->paymentsOnly()) {
            $registry = $this->app->make(WebhookEffectRegistry::class);

            $registry->on(MandateEstablished::class, StartSubscriptionOnMandate::class);
            $registry->on(PaymentSucceeded::class, SettleCycleOnPayment::class);
            $registry->on(PaymentFailed::class, FailCycleOnPayment::class);
        }

        $this->guardCredentials();
    }

    /**
     * The absolute URL Mollie posts its status pings at. Customers return to the checkout URLs instead.
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

    /** The webhook address of one payment account: the install's address, followed by the provider and the key. */
    private function accountWebhookUrl(Repository $config, PaymentAccount $account): string
    {
        return rtrim(self::webhookUrl($config), '/').'/'.$account->provider.'/'.$account->key;
    }

    /**
     * The countries the host declared for its terminals, keyed by a terminal's or a profile's id.
     *
     * @return array<array-key, mixed>
     */
    private static function terminalCountries(Repository $config): array
    {
        $declared = $config->get('billing.mollie.terminal_countries', []);

        return is_array($declared) ? $declared : [];
    }

    /**
     * The local engine this driver bills with, built from the container.
     *
     * The driver holds one, and the preview of the next invoice builds another the same way. The engine keeps
     * no state of its own, so the two price a cycle identically.
     */
    private static function engine(Container $app): LocalBillingEngine
    {
        return new LocalBillingEngine(
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
            $app->make(ProrationCreditCorrectionIssuer::class),
            $app->make(BillingEventLog::class),
            $app->make(PreNotifications::class),
        );
    }

    /** What squares a change inside a period collected in advance: this driver's engine and the credit ledger. */
    private static function restOfPeriod(Container $app): RestOfPeriod
    {
        return new RestOfPeriod(self::engine($app), $app->make(CreditLedger::class));
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
     * A webhook signing secret is deliberately not required. The classic ping of every payment carries no
     * signature at all, and an account without next-generation webhooks has no secret to configure, so
     * demanding one would refuse to boot every such install. The verifier checks a signature wherever one
     * is configured and arrives.
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
                'next-generation webhook events unsigned. The classic ping of each payment is never signed '.
                'and is checked by fetching the payment back either way — set billing.mollie.webhook_secret '.
                'if the Mollie dashboard has next-generation webhooks set up.',
            ),
        );
    }
}
