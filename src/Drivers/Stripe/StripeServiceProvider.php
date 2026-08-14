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
use Pushery\Billing\Contracts\MarketplaceWebhookEventMapper;
use Pushery\Billing\Contracts\MarketplaceWebhookVerifier;
use Pushery\Billing\Contracts\MerchantOnboarding;
use Pushery\Billing\Contracts\MerchantPriceProvisioner;
use Pushery\Billing\Contracts\MeterInspector;
use Pushery\Billing\Contracts\MovesMerchantShare;
use Pushery\Billing\Contracts\OneTimeCharge;
use Pushery\Billing\Contracts\PaymentCsp;
use Pushery\Billing\Contracts\PaymentMethods;
use Pushery\Billing\Contracts\ProrationStrategy;
use Pushery\Billing\Contracts\ReadsRoutedInvoiceCommission;
use Pushery\Billing\Contracts\SeatBilling;
use Pushery\Billing\Contracts\SubscriptionActions;
use Pushery\Billing\Contracts\SubscriptionSync;
use Pushery\Billing\Contracts\SupplyRegimeResolver;
use Pushery\Billing\Contracts\UpcomingInvoice;
use Pushery\Billing\Contracts\UsageReporter;
use Pushery\Billing\Contracts\WebhookEventMapper;
use Pushery\Billing\Contracts\WebhookVerifier;
use Pushery\Billing\Drivers\NullCustomerRegistry;
use Pushery\Billing\Events\AddonPurchased;
use Pushery\Billing\Events\AddonRefunded;
use Pushery\Billing\Events\ChargebackReceived;
use Pushery\Billing\Events\InvoiceCorrected;
use Pushery\Billing\Events\InvoiceFinalized;
use Pushery\Billing\Events\InvoiceUpcoming;
use Pushery\Billing\Events\MandateRevoked;
use Pushery\Billing\Events\MerchantAccountDeauthorized;
use Pushery\Billing\Events\MerchantAccountUpdated;
use Pushery\Billing\Events\MerchantPayoutFailed;
use Pushery\Billing\Events\MerchantTransferReversedByProvider;
use Pushery\Billing\Events\PaymentActionRequired;
use Pushery\Billing\Events\PaymentFailed;
use Pushery\Billing\Events\PaymentSucceeded;
use Pushery\Billing\Events\RoutedChargeAbandoned;
use Pushery\Billing\Events\RoutedChargeConfirmed;
use Pushery\Billing\Events\RoutedSubscriptionInvoicePaid;
use Pushery\Billing\Events\SubscriptionStateChanged;
use Pushery\Billing\Events\TrialEnding;
use Pushery\Billing\Support\BillingManager;
use Pushery\Billing\Support\WebhookSecretGuard;
use Pushery\Billing\Webhooks\Effects\ClaimChargebackClawback;
use Pushery\Billing\Webhooks\Effects\CorrectChainOnChargeback;
use Pushery\Billing\Webhooks\Effects\CreditAddonPurchase;
use Pushery\Billing\Webhooks\Effects\FlushUpcomingUsage;
use Pushery\Billing\Webhooks\Effects\GrantPurchasedContent;
use Pushery\Billing\Webhooks\Effects\MarkMerchantDeauthorized;
use Pushery\Billing\Webhooks\Effects\PersistInvoice;
use Pushery\Billing\Webhooks\Effects\PersistInvoiceCorrection;
use Pushery\Billing\Webhooks\Effects\RecordFailedMerchantPayout;
use Pushery\Billing\Webhooks\Effects\RecordProviderFee;
use Pushery\Billing\Webhooks\Effects\RecordProviderTransferReversal;
use Pushery\Billing\Webhooks\Effects\RecordRoutedSubscriptionCharge;
use Pushery\Billing\Webhooks\Effects\RefreshMerchantCapabilities;
use Pushery\Billing\Webhooks\Effects\ReopenWriteOffOnLateReceipt;
use Pushery\Billing\Webhooks\Effects\ReverseAddonPurchase;
use Pushery\Billing\Webhooks\Effects\RevokeAccessOnChargeback;
use Pushery\Billing\Webhooks\Effects\RevokeAccessOnRefund;
use Pushery\Billing\Webhooks\Effects\RevokeMandate;
use Pushery\Billing\Webhooks\Effects\SendDunningNotice;
use Pushery\Billing\Webhooks\Effects\SendPaymentActionRequiredNotice;
use Pushery\Billing\Webhooks\Effects\SendPaymentReceipt;
use Pushery\Billing\Webhooks\Effects\SendSubscriptionActivatedNotice;
use Pushery\Billing\Webhooks\Effects\SendSubscriptionCanceledNotice;
use Pushery\Billing\Webhooks\Effects\SendTrialEndingNotice;
use Pushery\Billing\Webhooks\Effects\SettleRoutedChargeOnConfirmation;
use Pushery\Billing\Webhooks\Effects\SyncPlanFromSubscription;
use Pushery\Billing\Webhooks\WebhookEffectRegistry;
use Stripe\StripeClient;

/**
 * Wires the Stripe driver into the container: a configured Stripe SDK client, the driver factory the
 * BillingManager resolves for the "stripe" name, and the concrete implementations of the neutral
 * account-hub and webhook contracts. Registered by the package's service provider; the future
 * Local-engine drivers ship their own providers that rebind these when they are the active driver.
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
        // The seam the routed-cycle effect reads its commission through. Bound in the DRIVER provider, so
        // an install on another driver never resolves a Stripe client for it -- and the effect itself never
        // learns whose API answered.
        $this->app->bind(ReadsRoutedInvoiceCommission::class, fn (Application $app): StripeRoutedInvoiceCommission => new StripeRoutedInvoiceCommission($app->make(StripeClient::class)));

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

        // The receiving side. Bound whenever the Stripe driver is active, not only with the marketplace
        // switched on: onboarding merchants and gating them comes BEFORE the switch in the go-live order,
        // so a binding that appeared only once the switch was on would be missing exactly when it is used.
        $this->app->bind(MerchantOnboarding::class, StripeMerchantOnboarding::class);

        // The second half of a separate-transfer sale. Bound here rather than in the neutral provider,
        // because moving a share is a provider operation and the neutral layer must not know how.
        $this->app->bind(MovesMerchantShare::class, StripeMerchantTransfers::class);
        $this->app->bind(MerchantPriceProvisioner::class, StripeMerchantPriceProvisioner::class);
        $this->app->bind(MarketplaceWebhookVerifier::class, StripeMarketplaceWebhookVerifier::class);
        $this->app->bind(MarketplaceWebhookEventMapper::class, StripeMarketplaceWebhookEventMapper::class);
        // Stripe books proration on its own side, but the account hub still previews the cost of a
        // swap before committing — the Stripe strategy does that via create_preview. This REPLACES the
        // generic strategy the core provider bound a moment ago, which is what makes that one a real
        // default rather than a described one: the core binds first, every driver overrides after.
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
            fn (): StripeDriver => new StripeDriver(
                new StripePaymentRails(
                    $this->app->make(StripeClient::class),
                    $this->app->make(Repository::class),
                    $this->app->make(SupplyRegimeResolver::class),
                ),
                // Built unconditionally, and that is not the same as switched on. The marketplace master
                // switch and the go-live checklist decide whether anything may USE these rails; the driver
                // only declares that it can. Wiring the capability to the switch would make the checklist
                // check its own precondition, and a checklist that passes because the feature is off is
                // the one shape it must never have.
                new StripeConnectRails(
                    $this->app->make(StripeClient::class),
                    $this->app->make(Repository::class),
                    'stripe',
                ),
            ),
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
        // Ownership beside the money, deliberately as a second effect: they are different facts with
        // different lifetimes, and folded together a failure in either half would roll back the other —
        // leaving a buyer charged, credited, and without the row saying they own what they paid for.
        $registry->on(AddonPurchased::class, GrantPurchasedContent::class);
        // The routed subscription cycle, and the one sale the money ledger never saw. A routed subscription
        // is priced with a RATE, so its commission exists once per cycle and only at the provider — which is
        // why this is the first effect in the package that reads from one. It asks the local subscription
        // first and stops there for every unrouted cycle, so an install that routes nothing pays nothing.
        $registry->on(RoutedSubscriptionInvoicePaid::class, RecordRoutedSubscriptionCharge::class);
        $registry->on(AddonRefunded::class, ReverseAddonPurchase::class);
        // Access beside the money, and switchable independently of it: a refund that leaves the work in place
        // is a real policy, and a build that welded the two together would make it impossible to express.
        $registry->on(AddonRefunded::class, RevokeAccessOnRefund::class);
        $registry->on(PaymentFailed::class, SendDunningNotice::class);
        // A payment the bank held for 3-D Secure: nudge the customer to confirm, or the subscription sits
        // incomplete while they think they subscribed.
        $registry->on(PaymentActionRequired::class, SendPaymentActionRequiredNotice::class);
        // The other half of the money conversation: the package told the customer when their money did NOT
        // move; this tells them when it did.
        $registry->on(PaymentSucceeded::class, SendPaymentReceipt::class);
        // And the case where the money was not expected at all: a payment against a receivable somebody had
        // already written off as uncollectible. Its own effect rather than a branch in the receipt, because
        // it decides something the receipt has no opinion on -- whether a tax correction was a judgement the
        // future just disagreed with. Inert until an install actually writes something off.
        $registry->on(PaymentSucceeded::class, ReopenWriteOffOnLateReceipt::class);
        $registry->on(InvoiceFinalized::class, PersistInvoice::class);
        $registry->on(InvoiceCorrected::class, PersistInvoiceCorrection::class);
        $registry->on(MandateRevoked::class, RevokeMandate::class);
        $registry->on(InvoiceUpcoming::class, FlushUpcomingUsage::class);
        $registry->on(TrialEnding::class, SendTrialEndingNotice::class);

        // The merchant events. Registered unconditionally, and gating the registration on config instead
        // would put a config read on a path that already has a switch above it.
        //
        // MOST of them are harmless without the marketplace because the only thing that emits them is the
        // marketplace mapper, which only the merchant endpoint reads, and that endpoint does not exist
        // while the marketplace is off.
        //
        // TWO OF THEM ARE NOT REACHED THAT WAY, and the distinction matters because it is the safety
        // argument. `RoutedChargeConfirmed` and `RoutedChargeAbandoned` are emitted by the PLATFORM mapper
        // (StripeWebhookEventMapper::routedChargeEvents()), on the ordinary platform webhook endpoint that
        // exists whenever the Stripe driver is active — marketplace or not. The marketplace mapper never
        // constructs them.
        //
        // They are still harmless, for a DIFFERENT reason, and it is worth naming rather than inheriting:
        // the effect matches the provider's reference against a routed charge this package itself wrote,
        // so an event naming a payment it never routed finds nothing and does nothing. An ordinary
        // one-time checkout carries no invoice either and reaches the same effect — SettleRoutedCharge-
        // OnConfirmation says so in its own docblock. Anyone narrowing that match must know these two
        // arrive on a single-seller install too.
        $registry->on(MerchantAccountUpdated::class, RefreshMerchantCapabilities::class);
        $registry->on(MerchantAccountDeauthorized::class, MarkMerchantDeauthorized::class);
        $registry->on(RoutedChargeConfirmed::class, SettleRoutedChargeOnConfirmation::class);
        $registry->on(RoutedChargeAbandoned::class, SettleRoutedChargeOnConfirmation::class);
        // A reversal the provider performed on its own. Registered beside the chargeback effects because it
        // answers the same question from the other direction: money that left the merchant without this
        // platform asking for it.
        $registry->on(MerchantTransferReversedByProvider::class, RecordProviderTransferReversal::class);
        $registry->on(MerchantPayoutFailed::class, RecordFailedMerchantPayout::class);
        $registry->on(ChargebackReceived::class, RecordProviderFee::class);
        // A lost dispute ends access too. Its own effect rather than a branch in the fee one, for the same
        // reason ownership is separate from crediting: different facts, and a failure in one must not undo
        // the other.
        $registry->on(ChargebackReceived::class, RevokeAccessOnChargeback::class);
        // And the money side: the correcting documents the lost dispute owes. Its own effect again, because
        // it is the one that must distinguish WHICH legs are corrected -- a fraud loss corrects the buyer's
        // side only, while a supply the buyer never received corrects the creator's settlement with it.
        $registry->on(ChargebackReceived::class, CorrectChainOnChargeback::class);
        // And the money itself. Its own effect for the third time, and for a reason the other two do not
        // have: it is the only one that has to reach the PROVIDER, which an effect cannot do from inside
        // the transaction it runs in. So it claims here and spends in a job.
        $registry->on(ChargebackReceived::class, ClaimChargebackClawback::class);
    }

    /** In production with Stripe active, refuse to boot without a webhook signing secret. */
    private function guardWebhookSecret(Application $app): void
    {
        $config = $app->make(Repository::class);

        $stripeActive = (bool) $config->get('billing.enabled', true)
            && $config->get('billing.default', 'stripe') === 'stripe';

        if (! $stripeActive) {
            return;
        }

        $secret = $config->get('cashier.webhook.secret');
        $guard = $app->make(WebhookSecretGuard::class);

        $guard->ensureConfigured(
            'stripe',
            $app->environment(),
            is_string($secret) && $secret !== '' ? $secret : null,
        );

        if (! (bool) $config->get('billing.marketplace.enabled', false)) {
            return;
        }

        // The merchant endpoint has its OWN secret, and a marketplace running without it is the quietest
        // possible failure: every merchant event fails verification, so the capability flags simply never
        // move — a merchant who lost their payout capability keeps being paid, and nothing anywhere reports
        // an error. Refuse to boot instead.
        $merchantSecret = $config->get(StripeMarketplaceWebhookVerifier::SECRET_KEY);

        $guard->ensureConfigured(
            'stripe (marketplace)',
            $app->environment(),
            is_string($merchantSecret) && $merchantSecret !== '' ? $merchantSecret : null,
        );
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
