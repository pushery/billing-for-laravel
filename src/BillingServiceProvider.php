<?php

declare(strict_types=1);

namespace Pushery\Billing;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier;
use Livewire\Livewire;
use Override;
use Pushery\Billing\Catalogs\ConfigAddonCatalog;
use Pushery\Billing\Catalogs\ConfigPlanCatalog;
use Pushery\Billing\Catalogs\ConfigTierCatalog;
use Pushery\Billing\Catalogs\MeterCatalog;
use Pushery\Billing\Catalogs\SingleMerchantCatalog;
use Pushery\Billing\Console\Commands\AdvanceBuyerProtectionCommand;
use Pushery\Billing\Console\Commands\AdvanceDunningCommand;
use Pushery\Billing\Console\Commands\AnnounceLapsedAttestationsCommand;
use Pushery\Billing\Console\Commands\AnnounceUpcomingFilingsCommand;
use Pushery\Billing\Console\Commands\AnnounceVoucherVolumeCommand;
use Pushery\Billing\Console\Commands\BillingRunCommand;
use Pushery\Billing\Console\Commands\CancelSubscriptionCommand;
use Pushery\Billing\Console\Commands\CheckMetersCommand;
use Pushery\Billing\Console\Commands\CheckTaxRatesCommand;
use Pushery\Billing\Console\Commands\DatevExportCommand;
use Pushery\Billing\Console\Commands\DoctorCommand;
use Pushery\Billing\Console\Commands\EraseOwnerCommand;
use Pushery\Billing\Console\Commands\ExpireDelinquentSubscriptionsCommand;
use Pushery\Billing\Console\Commands\ExportOwnerCommand;
use Pushery\Billing\Console\Commands\FlushUsageCommand;
use Pushery\Billing\Console\Commands\FreezeReportingRatesCommand;
use Pushery\Billing\Console\Commands\GrantTierCommand;
use Pushery\Billing\Console\Commands\ImportExchangeRatesCommand;
use Pushery\Billing\Console\Commands\InstallCommand;
use Pushery\Billing\Console\Commands\MarketplacePreflightCommand;
use Pushery\Billing\Console\Commands\MerchantOnboardCommand;
use Pushery\Billing\Console\Commands\MerchantReopenCommand;
use Pushery\Billing\Console\Commands\MerchantStatusCommand;
use Pushery\Billing\Console\Commands\ProbeRatesCommand;
use Pushery\Billing\Console\Commands\PruneBillingCommand;
use Pushery\Billing\Console\Commands\ReconcileMerchantJournalCommand;
use Pushery\Billing\Console\Commands\ReconcileTaxStatusCommand;
use Pushery\Billing\Console\Commands\ReconcileUsageCommand;
use Pushery\Billing\Console\Commands\RecordMarketAccessCommand;
use Pushery\Billing\Console\Commands\RefreshMerchantCapabilitiesCommand;
use Pushery\Billing\Console\Commands\ReleaseAbandonedClaimCommand;
use Pushery\Billing\Console\Commands\RemindDelinquentSubscriptionsCommand;
use Pushery\Billing\Console\Commands\ReplayWebhooksCommand;
use Pushery\Billing\Console\Commands\ReportingFileCommand;
use Pushery\Billing\Console\Commands\ReportingRunCommand;
use Pushery\Billing\Console\Commands\SyncSubscriptionsCommand;
use Pushery\Billing\Console\Commands\TaxReturnExportCommand;
use Pushery\Billing\Console\Commands\WarnEndingTrialsCommand;
use Pushery\Billing\Console\Commands\WarnExpiringCardsCommand;
use Pushery\Billing\Console\Commands\WarnUnestablishedStandingsCommand;
use Pushery\Billing\Consumer\GermanConformityUpdatePolicy;
use Pushery\Billing\Consumer\GermanWithdrawalPolicy;
use Pushery\Billing\ContentOwnership\AssumesEverythingAvailable;
use Pushery\Billing\ContentOwnership\DatabaseContentAccessReader;
use Pushery\Billing\ContentOwnership\DisabledContentAccessReader;
use Pushery\Billing\ContentOwnership\GrantsNothingBySubscription;
use Pushery\Billing\ContentOwnership\NoAddonContent;
use Pushery\Billing\ContentOwnership\NoBundles;
use Pushery\Billing\ContentOwnership\NoContentVersions;
use Pushery\Billing\ContentOwnership\NoUpdatePolicyPreferences;
use Pushery\Billing\Contracts\AddonCatalog;
use Pushery\Billing\Contracts\AddonContentMap;
use Pushery\Billing\Contracts\AnnualEarningsCounter;
use Pushery\Billing\Contracts\BillingEntityResolver;
use Pushery\Billing\Contracts\BundleContents;
use Pushery\Billing\Contracts\CanReceiveMoney;
use Pushery\Billing\Contracts\CanTransactMoney;
use Pushery\Billing\Contracts\ConformityUpdatePolicy;
use Pushery\Billing\Contracts\ConsumerWithdrawalPolicy;
use Pushery\Billing\Contracts\ContentAccessReader;
use Pushery\Billing\Contracts\ContentCatalog;
use Pushery\Billing\Contracts\ContentVersions;
use Pushery\Billing\Contracts\CountryEvidencePolicy;
use Pushery\Billing\Contracts\CountsEarnings;
use Pushery\Billing\Contracts\CreatorTaxStatusResolver;
use Pushery\Billing\Contracts\CreditSync;
use Pushery\Billing\Contracts\CrossBorderSalesCounter;
use Pushery\Billing\Contracts\CustomerRegistry;
use Pushery\Billing\Contracts\CycleAmountResolver;
use Pushery\Billing\Contracts\DatevAccountResolver;
use Pushery\Billing\Contracts\DefinesUnionMembership;
use Pushery\Billing\Contracts\DiscountResolver;
use Pushery\Billing\Contracts\DunningGuard;
use Pushery\Billing\Contracts\DunningNotifier;
use Pushery\Billing\Contracts\EInvoice;
use Pushery\Billing\Contracts\ExchangeRateSource;
use Pushery\Billing\Contracts\GoLiveChecklist;
use Pushery\Billing\Contracts\Invoices;
use Pushery\Billing\Contracts\IpCountryResolver;
use Pushery\Billing\Contracts\LateFees;
use Pushery\Billing\Contracts\LedgerBalanceReader;
use Pushery\Billing\Contracts\License;
use Pushery\Billing\Contracts\ListsEarningCurrencies;
use Pushery\Billing\Contracts\MandateNotifier;
use Pushery\Billing\Contracts\MerchantAccountDirectory;
use Pushery\Billing\Contracts\MerchantCatalog;
use Pushery\Billing\Contracts\MerchantPartyResolver;
use Pushery\Billing\Contracts\MerchantResolver;
use Pushery\Billing\Contracts\MerchantScopedCustomerDirectory;
use Pushery\Billing\Contracts\MeterInspector;
use Pushery\Billing\Contracts\MovesMerchantShare;
use Pushery\Billing\Contracts\PaymentActionNotifier;
use Pushery\Billing\Contracts\PdfRenderer;
use Pushery\Billing\Contracts\PlanCatalog;
use Pushery\Billing\Contracts\PlatformFeeResolver;
use Pushery\Billing\Contracts\ProductTaxonomy;
use Pushery\Billing\Contracts\ProrationStrategy;
use Pushery\Billing\Contracts\PublishesExchangeRates;
use Pushery\Billing\Contracts\ReceiptNotifier;
use Pushery\Billing\Contracts\RendersReportingRecord;
use Pushery\Billing\Contracts\ReportingProfile;
use Pushery\Billing\Contracts\ScheduleHeartbeat;
use Pushery\Billing\Contracts\SeatBilling;
use Pushery\Billing\Contracts\SellerOfRecordResolver;
use Pushery\Billing\Contracts\SellerPartyResolver;
use Pushery\Billing\Contracts\SmallBusinessIdValidator;
use Pushery\Billing\Contracts\SubscriptionActions;
use Pushery\Billing\Contracts\SubscriptionContentScope;
use Pushery\Billing\Contracts\SubscriptionNotifier;
use Pushery\Billing\Contracts\SubscriptionStateReader;
use Pushery\Billing\Contracts\SupplyRegimeResolver;
use Pushery\Billing\Contracts\SuspensionLadder;
use Pushery\Billing\Contracts\SuspensionNotifier;
use Pushery\Billing\Contracts\TaxCalculator;
use Pushery\Billing\Contracts\TaxDisclosurePolicy;
use Pushery\Billing\Contracts\TierCatalog;
use Pushery\Billing\Contracts\TierResolver;
use Pushery\Billing\Contracts\TrialNotifier;
use Pushery\Billing\Contracts\UpcomingInvoice;
use Pushery\Billing\Contracts\UpdatePolicyCatalog;
use Pushery\Billing\Contracts\UsageHistoryProvider;
use Pushery\Billing\Contracts\UsageNotifier;
use Pushery\Billing\Contracts\UsageProvider;
use Pushery\Billing\Contracts\UsageReporter;
use Pushery\Billing\Contracts\VatIdValidator;
use Pushery\Billing\Discounts\ConfigDiscountResolver;
use Pushery\Billing\Drivers\Mollie\MollieServiceProvider;
use Pushery\Billing\Drivers\NullCreditSync;
use Pushery\Billing\Drivers\NullCustomerRegistry;
use Pushery\Billing\Drivers\NullInvoices;
use Pushery\Billing\Drivers\NullMeterInspector;
use Pushery\Billing\Drivers\NullSeatBilling;
use Pushery\Billing\Drivers\NullSubscriptionActions;
use Pushery\Billing\Drivers\NullUpcomingInvoice;
use Pushery\Billing\Drivers\Stripe\StripeServiceProvider;
use Pushery\Billing\Dunning\LadderSuspension;
use Pushery\Billing\Dunning\LocalDunningGuard;
use Pushery\Billing\Dunning\NullLateFees;
use Pushery\Billing\Eligibility\AlwaysEligible;
use Pushery\Billing\Eligibility\AlwaysReceivable;
use Pushery\Billing\Entitlements\ConfigLicense;
use Pushery\Billing\Events\BillableAccountDeleting;
use Pushery\Billing\Events\CreatorTaxStatusChanged;
use Pushery\Billing\Http\Controllers\BillingController;
use Pushery\Billing\Http\Middleware\AccountContentSecurityPolicy;
use Pushery\Billing\Http\Middleware\EnforceDunning;
use Pushery\Billing\Http\Middleware\EnforceQuota;
use Pushery\Billing\Http\Middleware\EnforceSuspension;
use Pushery\Billing\Invoicing\ConfigDatevAccountResolver;
use Pushery\Billing\Invoicing\UnavailablePdfRenderer;
use Pushery\Billing\Invoicing\XRechnungInvoice;
use Pushery\Billing\Listeners\NotifyMerchantOfAutomaticTaxStatusChange;
use Pushery\Billing\Listeners\StopBillingForDeletedAccount;
use Pushery\Billing\Listeners\SyncSeatsOnMembershipChange;
use Pushery\Billing\Livewire\AccountOverview;
use Pushery\Billing\Livewire\AccountRealtime;
use Pushery\Billing\Livewire\BillingAdminConsole;
use Pushery\Billing\Livewire\DangerZone;
use Pushery\Billing\Livewire\InvoiceHistory;
use Pushery\Billing\Livewire\ManageSubscription;
use Pushery\Billing\Livewire\PaymentMethodManager;
use Pushery\Billing\Livewire\PaymentRecovery;
use Pushery\Billing\Livewire\SubscriptionOverview;
use Pushery\Billing\Livewire\UsageHistory;
use Pushery\Billing\Livewire\UsageOverview;
use Pushery\Billing\Marketplace\BuyerProtectionClock;
use Pushery\Billing\Marketplace\ChargeRoutingConsistencyGuard;
use Pushery\Billing\Marketplace\ConfigPlatformFeeResolver;
use Pushery\Billing\Marketplace\ConfigSellerOfRecordResolver;
use Pushery\Billing\Marketplace\ConfigSellerPartyResolver;
use Pushery\Billing\Marketplace\ConfigSupplyRegimeResolver;
use Pushery\Billing\Marketplace\CreatorTaxDisclosureGuard;
use Pushery\Billing\Marketplace\CreatorTaxStatusHold;
use Pushery\Billing\Marketplace\CreatorTaxStatusLedger;
use Pushery\Billing\Marketplace\DatabaseMerchantAccountDirectory;
use Pushery\Billing\Marketplace\DatabaseMerchantScopedCustomerDirectory;
use Pushery\Billing\Marketplace\DocumentDeliveryLog;
use Pushery\Billing\Marketplace\DocumentNumberAllocator;
use Pushery\Billing\Marketplace\FanReceiptIssuer;
use Pushery\Billing\Marketplace\FanReceiptTierResolver;
use Pushery\Billing\Marketplace\GermanTaxDisclosurePolicy;
use Pushery\Billing\Marketplace\InboundTaxMatrix;
use Pushery\Billing\Marketplace\MarketAllowlist;
use Pushery\Billing\Marketplace\MarketplaceSaleContext;
use Pushery\Billing\Marketplace\MerchantChargeAnnualEarningsCounter;
use Pushery\Billing\Marketplace\MerchantChargeLedgerBalanceReader;
use Pushery\Billing\Marketplace\NullMerchantPartyResolver;
use Pushery\Billing\Marketplace\NullMerchantResolver;
use Pushery\Billing\Marketplace\ProductClassifier;
use Pushery\Billing\Marketplace\Reporting\DelimitedSellerRecord;
use Pushery\Billing\Marketplace\ReportingPlausibilityRules;
use Pushery\Billing\Marketplace\RoutedChargeLedger;
use Pushery\Billing\Marketplace\RoutedPayment;
use Pushery\Billing\Marketplace\SelfBillingAgreementGuard;
use Pushery\Billing\Marketplace\SelfBillingEngine;
use Pushery\Billing\Notifiers\LaravelDunningNotifier;
use Pushery\Billing\Preflight\CheckpointRegistry;
use Pushery\Billing\Preflight\Profiles\GermanProductTaxonomy;
use Pushery\Billing\Preflight\Profiles\GermanReportingProfile;
use Pushery\Billing\Proration\DelegatedProrationStrategy;
use Pushery\Billing\Resolvers\ColumnTierResolver;
use Pushery\Billing\Resolvers\ConfigBillingEntityResolver;
use Pushery\Billing\Resolvers\PlanCycleAmountResolver;
use Pushery\Billing\Support\BillingConfigValidator;
use Pushery\Billing\Support\BillingManager;
use Pushery\Billing\Support\CustodyGuard;
use Pushery\Billing\Support\GoLivePreflightGuard;
use Pushery\Billing\Support\LocalSubscriptionStateReader;
use Pushery\Billing\Support\MarketplaceSupportGuard;
use Pushery\Billing\Support\MeteringSupportGuard;
use Pushery\Billing\Support\NullScheduleHeartbeat;
use Pushery\Billing\Support\RetentionFloorGuard;
use Pushery\Billing\Support\RetentionMatrix;
use Pushery\Billing\Support\TaxSupportGuard;
use Pushery\Billing\Tax\DatabaseExchangeRateSource;
use Pushery\Billing\Tax\EcbRatePublisher;
use Pushery\Billing\Tax\EuOssTaxCalculator;
use Pushery\Billing\Tax\FreezeExchangeRateOnDocument;
use Pushery\Billing\Tax\InvoiceCrossBorderSalesCounter;
use Pushery\Billing\Tax\NoExchangeRateSource;
use Pushery\Billing\Tax\NullIpCountryResolver;
use Pushery\Billing\Tax\NullSmallBusinessIdValidator;
use Pushery\Billing\Tax\NullVatIdValidator;
use Pushery\Billing\Tax\PaymentCountryLeadsPolicy;
use Pushery\Billing\Tax\PlaceOfSupplyResolver;
use Pushery\Billing\Tax\ShippedTaxRates;
use Pushery\Billing\Tax\TaxCalculatorFactory;
use Pushery\Billing\Usage\CounterUsageProvider;
use Pushery\Billing\Usage\DatabaseUsageHistory;
use Pushery\Billing\Usage\NullUsageProvider;
use Pushery\Billing\Usage\NullUsageReporter;
use Pushery\Billing\View\Components\Banner;
use Pushery\Billing\Webhooks\WebhookEffectRegistry;

final class BillingServiceProvider extends ServiceProvider
{
    /**
     * Whether the bundled migrations are registered automatically. Disable with
     * self::ignoreMigrations() to publish and manage them in the host app instead.
     */
    public static bool $runsMigrations = true;

    public static function ignoreMigrations(): void
    {
        self::$runsMigrations = false;
    }

    #[Override]
    public function register(): void
    {
        // Erasing an owner touches no provider unless the active driver's registry is bound over this and
        // the app has asked for it: deleting a customer at the provider is irreversible.
        $this->app->bind(CustomerRegistry::class, NullCustomerRegistry::class);
        // Credit stays local unless the active driver can mirror it to the provider.
        $this->app->bind(CreditSync::class, NullCreditSync::class);

        $this->mergeConfigFrom(__DIR__.'/../config/billing.php', 'billing');
        $this->mergeConfigFrom(__DIR__.'/../config/account.php', 'account');
        $this->mergeConfigFrom(__DIR__.'/../config/license.php', 'license');

        // Master switch off → the whole billing surface disappears, including Cashier's own routes
        // (webhook + payment confirmation). Set before Cashier boots so they are never registered.
        if (! (bool) $this->app->make(Repository::class)->get('billing.enabled', true)) {
            Cashier::ignoreRoutes();
        }

        $this->app->singleton(
            BillingManager::class,
            static fn (Application $app): BillingManager => new BillingManager($app->make(Repository::class)),
        );

        $this->app->singleton(WebhookEffectRegistry::class);
        $this->app->bind(ScheduleHeartbeat::class, NullScheduleHeartbeat::class);

        $this->app->bind(DiscountResolver::class, ConfigDiscountResolver::class);

        // Resolves a DATEV business transaction to its account from the configured chart. The default reads
        // the single-seller revenue account, so the export is byte-identical until a chart is selected.
        $this->app->bind(DatevAccountResolver::class, ConfigDatevAccountResolver::class);

        // The PDF step of the local invoice renderer is a seam: the package produces the invoice HTML but
        // ships no PDF toolchain. The default refuses loudly (bind dompdf/Snappy to enable PDF downloads).
        $this->app->bind(PdfRenderer::class, UnavailablePdfRenderer::class);

        // What a subscription line costs for a cycle. The default reads a fixed line's stored amount and
        // hands a metered one to the resolver named on the line, so nothing rates usage unless something was
        // asked to — a driver that rates remotely keeps doing so, and a local engine binds or names a
        // resolver that rates from the package's own counters.
        $this->app->bind(CycleAmountResolver::class, PlanCycleAmountResolver::class);

        // Marketplace: the seller-of-record posture resolver. Bound always (cheap); only the marketplace
        // paths consult it, and the master switch keeps them unreachable when billing.marketplace.enabled is
        // false, so the single-merchant behavior is unchanged.
        $this->app->bind(SellerOfRecordResolver::class, ConfigSellerOfRecordResolver::class);
        $this->app->bind(SupplyRegimeResolver::class, ConfigSupplyRegimeResolver::class);

        // Who a document names as seller. The default is the platform, so single-seller output is unchanged;
        // a marketplace consumer binds a resolver that names the merchant where the frozen posture calls for
        // it. The e-invoice writers fall back to this same default when no resolver is injected.
        $this->app->bind(SellerPartyResolver::class, ConfigSellerPartyResolver::class);

        // How to read a merchant's own invoice identity, for a self-billed document that names them as
        // seller. There is no default — a merchant's name and address are the consumer's data — so the
        // shipped binding fails closed; a marketplace that self-bills binds a resolver that reads its
        // merchants, and a single-seller install never reaches it.
        $this->app->bind(MerchantPartyResolver::class, NullMerchantPartyResolver::class);

        // What the platform keeps of a routed sale. Behind a contract because a commission is a commercial
        // arrangement and arrangements differ per merchant; the shipped answer is the configured one, which
        // defaults to nothing at all.
        $this->app->bind(PlatformFeeResolver::class, ConfigPlatformFeeResolver::class);

        // Which merchant a checkout routes to, resolved implicitly from the app's own context. The shipped
        // default answers null (a platform sale), so a single-seller install is unrouted and unchanged; a
        // marketplace binds its own resolver. The checkout consults it only when the marketplace is on.
        $this->app->bind(MerchantResolver::class, NullMerchantResolver::class);

        // Routing a payment and recording it, as one operation. Bound explicitly because it needs the ACTIVE
        // driver, which the container cannot autowire — a driver is resolved by name through the manager,
        // not by type. Without this binding the class would exist and not be reachable, which is the shape
        // of defect this whole layer has been paying for.
        $this->app->bind(RoutedPayment::class, fn (Application $app): RoutedPayment => new RoutedPayment(
            $app->make(BillingManager::class)->driver(),
            $app->make(RoutedChargeLedger::class),
            // The receiving gate, resolved through the container so a consumer that composed one gets it
            // here too. The shipped default is AlwaysReceivable — this binding does not decide who may
            // receive, it makes sure whatever DID decide is actually asked on the one path that charges.
            $app->make(CanReceiveMoney::class),
            // The tax-standing gate, likewise resolved rather than constructed here: it reads config and a
            // jurisdiction profile, both of which a consumer can replace. Its own enforcement date decides
            // whether it holds anybody, so wiring it costs an install that has not set one nothing.
            $app->make(CreatorTaxStatusHold::class),
            // The pairing check and the marketplace context that feeds it. Both sibling charge paths already
            // consult these; this one is the only place that reaches PaymentRails::charge() and it did not.
            // The context replaced a bare posture resolver: the derivation it wraps stood in three lanes and
            // one of them had already drifted.
            $app->make(ChargeRoutingConsistencyGuard::class),
            $app->make(MarketplaceSaleContext::class),
            $app->make(Repository::class),
            // The classifier, so "no sale without a classification" is enforced where a sale happens rather
            // than only where somebody remembers to ask. It refused correctly and unreachably until this
            // path was obliged to consult it.
            $app->make(ProductClassifier::class),
            // The buyer's document and the rule that decides its tier. Wired here for the same reason as
            // everything above it: this path took the money and issued nothing, and a receipt a consumer
            // has to remember to ask for is a receipt most sales will not have.
            $app->make(FanReceiptIssuer::class),
            $app->make(FanReceiptTierResolver::class),
            // Resolved only if something bound it. A destination-charge install never needs it, and a
            // separate-transfer sale without it throws BEFORE the buyer is charged rather than after.
            transfers: $app->bound(MovesMerchantShare::class) ? $app->make(MovesMerchantShare::class) : null,
            // The buyer-protection clock, so the switch can actually stop the transfer. Passed always and
            // consulted only when the switch is on: the clock alone changes nothing, and an install that
            // never turns it on takes the same path it always took.
            protection: $app->make(BuyerProtectionClock::class),
        ));

        // The clock, with the two seams a release needs and the dispatcher it never had. Bound explicitly
        // for the same container subtlety documented below: nullable parameters WITH defaults are preferred
        // over resolution, so autowiring would hand it three nulls — and a hold would open, be decided, and
        // never pay anybody, silently.
        $this->app->bind(BuyerProtectionClock::class, fn (Application $app): BuyerProtectionClock => new BuyerProtectionClock(
            $app->make(Repository::class),
            $app->bound(MovesMerchantShare::class) ? $app->make(MovesMerchantShare::class) : null,
            $app->bound(MerchantAccountDirectory::class) ? $app->make(MerchantAccountDirectory::class) : null,
            $app->make(Dispatcher::class),
        ));

        // Bound explicitly, and the reason is a container subtlety that cost a debugging round: the engine's
        // exchange-rate seam is a nullable parameter WITH a default, and for a class the container has no
        // binding for, Laravel prefers that default over resolving it. So autowiring quietly handed the
        // engine `null` and every foreign-currency settlement went through unfrozen — a seam that exists,
        // resolves in isolation, and never fires. Exactly the shape of defect this layer keeps finding.
        //
        // The registry beside it resolved fine, because it IS bound. That asymmetry is what made the
        // failure look like a logic bug rather than a wiring one.
        $this->app->bind(SelfBillingEngine::class, fn (Application $app): SelfBillingEngine => new SelfBillingEngine(
            $app->make(CreatorTaxStatusResolver::class),
            $app->make(InboundTaxMatrix::class),
            $app->make(DocumentNumberAllocator::class),
            $app->make(SelfBillingAgreementGuard::class),
            $app->make(CreatorTaxDisclosureGuard::class),
            $app->make(MerchantPartyResolver::class),
            $app->make(Repository::class),
            $app->make(DocumentDeliveryLog::class),
            $app->make(FreezeExchangeRateOnDocument::class),
            $app->make(CheckpointRegistry::class),
        ));

        // The read-only earnings balance: a pure projection over the routed-charge record, no state of its
        // own and no provider reach. It cannot move money — that is the whole point of shipping it.
        $this->app->bind(LedgerBalanceReader::class, MerchantChargeLedgerBalanceReader::class);
        // The enumeration is a SEPARATE binding on purpose, not a widened LedgerBalanceReader: that contract
        // is documented as implementable by a consumer, and adding a method to it would be a fatal error in
        // their class on the next update. Bound to the same projection, so both answers come from one query
        // shape over one table.
        $this->app->bind(ListsEarningCurrencies::class, MerchantChargeLedgerBalanceReader::class);

        // The jurisdiction-neutral per-year earnings count a threshold monitor evaluates. A projection over
        // the same routed-charge record; the profile decides what a limit break means, the count carries none.
        $this->app->bind(AnnualEarningsCounter::class, MerchantChargeAnnualEarningsCounter::class);
        // The same implementation under the window-taking seam. Bound beside the annual one rather than
        // replacing it: the annual contract is implemented outside this package, so it stays resolvable
        // exactly as it was for anybody who binds their own.
        $this->app->bind(CountsEarnings::class, MerchantChargeAnnualEarningsCounter::class);
        // The cross-border pot the distance-sale threshold reads. A projection over the invoices, so it can
        // be rebuilt at any time and come out the same — a stored total drifts the first time a document is
        // corrected, in the direction nobody checks.
        $this->app->bind(CrossBorderSalesCounter::class, InvoiceCrossBorderSalesCounter::class);

        // Fan-chosen pricing (tips and pay-what-you-want). Both it and the routed pricing it wraps take
        // only the config repository, which the container resolves, so no explicit wiring is needed —
        // and both are inert while the marketplace switch is off.

        // How contradicting country signals settle into one answer. A class rather than a branch in the
        // checkout: the reading is a legal one, and a consumer advised differently swaps it instead of
        // forking. Its version travels with every answer, so a replacement changes what happens next
        // rather than what already happened.
        $this->app->bind(CountryEvidencePolicy::class, PaymentCountryLeadsPolicy::class);

        // The consumer-withdrawal reading, bound to the German one. Behind its own profile, off by
        // default; a consumer elsewhere binds their own and the core stays free of any statute.
        $this->app->bind(ConsumerWithdrawalPolicy::class, GermanWithdrawalPolicy::class);

        // The conformity obligation reads from its own profile, bound beside the withdrawal one rather than
        // folded into it: they answer different questions and a jurisdiction may well change one without the
        // other. Neither is live until billing.consumer_rights.profile is set.
        $this->app->bind(ConformityUpdatePolicy::class, GermanConformityUpdatePolicy::class);

        // What a jurisdiction makes of each kind of product. The archetypes are a fact about commerce — a
        // download is a download everywhere — but what follows from one is not, so the consequences live in
        // a profile. Bound to the German reading; a consumer elsewhere binds their own and reads none of it.
        $this->app->bind(ProductTaxonomy::class, GermanProductTaxonomy::class);

        // Which information the active reporting regime asks a seller for. In the profile, never the core:
        // a consumer elsewhere needs entirely different fields, and receiving one country's would be both
        // wrong and a privacy problem — they would be collecting data no law asks them for.
        $this->app->bind(ReportingProfile::class, GermanReportingProfile::class);

        // Whether a self-billed document may state tax at all, per the creator's standing. In the profile:
        // the consequence of stating it wrongly (the recipient owing the tax) is a jurisdiction's rule, and
        // the whitelist of permitted standings is the German one. The guard that enforces it is neutral.
        $this->app->bind(TaxDisclosurePolicy::class, GermanTaxDisclosurePolicy::class);

        // The receiving side's neutral defaults. Both are bound ALWAYS and both are inert without the
        // marketplace: nothing routes money to a merchant, so nothing consults the gate, and the directory
        // reads a table a single-seller install never writes to.
        //
        // The default gate PERMITS, matching the paying side's AlwaysEligible. A marketplace consumer binds
        // ComposedReceiveGate with ProviderCapabilityCheck and its own predicates — the fail-closed shape.
        //
        // The go-live checklist now ASKS whether they did: `ReceivingGateCheckpoint` fails when a live
        // marketplace still resolves this binding to AlwaysReceivable. This comment used to say the
        // checklist expected the composed shape, and it did not — it never asked, so the run came back green
        // over a marketplace routing money to accounts nobody had looked at. A comment that describes a
        // guarantee no code provides is worse than none: it is the reason nobody goes looking.
        // Bound to a REFUSAL rather than left unbound, and the difference shows at the moment it fires: an
        // unbound contract produces a container error naming an interface, which reads as a wiring mistake
        // in the consumer's app. This produces a sentence saying the package ships no rates, why it ships
        // none, and what to do. A single-currency install never converts and never reaches either.
        //
        // With the local store switched on, the same seam reads the rates the consumer imported. The refusal
        // does not go away, it moves down a level: an empty store still refuses, and now names the currency,
        // the day and the rule that were asked for -- which points at the period nobody imported instead of
        // at the wiring.
        $this->app->bind(ExchangeRateSource::class, static fn (Application $app): ExchangeRateSource => $app
            ->make(Repository::class)
            ->boolean('billing.tax_exchange_rates.enabled')
            ? $app->make(DatabaseExchangeRateSource::class)
            : $app->make(NoExchangeRateSource::class));

        $this->app->bind(CanReceiveMoney::class, AlwaysReceivable::class);
        $this->app->bind(MerchantAccountDirectory::class, static function (Application $app): MerchantAccountDirectory {
            // Read defensively, like every other driver-name read. A misconfigured value must fall back to
            // the shipped driver rather than scope the lookup to an empty provider name, which would resolve
            // every merchant to no account at all — a silent, total denial that looks like nobody onboarded.
            $driver = $app->make(Repository::class)->get('billing.default', 'stripe');

            return new DatabaseMerchantAccountDirectory(is_string($driver) && $driver !== '' ? $driver : 'stripe');
        });

        // The account-scoped customer lookup. Its own contract, so no existing caller can accidentally get
        // multi-account semantics and no marketplace caller can accidentally miss them.
        // A creator's tax standing over time. Bound always and inert without the marketplace: only a routed
        // sale ever asks, and the table a single-seller install never writes to answers "unclarified".
        $this->app->bind(CreatorTaxStatusResolver::class, CreatorTaxStatusLedger::class);

        $this->app->bind(MerchantScopedCustomerDirectory::class, static function (Application $app): MerchantScopedCustomerDirectory {
            $driver = $app->make(Repository::class)->get('billing.default', 'stripe');

            return new DatabaseMerchantScopedCustomerDirectory(is_string($driver) && $driver !== '' ? $driver : 'stripe');
        });

        // The go-live checklist's registry. A SINGLETON, because a consumer adds its own checkpoints to the
        // one instance the checklist will later read; a fresh instance per resolution would silently drop
        // every point the consumer registered and still report the checklist as clear.
        // The retention rules. A SINGLETON, because a consumer declares rules for its own objects on the
        // one instance the prune run will read; a fresh instance per resolution would drop them and prune
        // nothing while reporting a complete rule set.
        $this->app->singleton(RetentionMatrix::class);

        $this->app->singleton(CheckpointRegistry::class);
        $this->app->alias(CheckpointRegistry::class, GoLiveChecklist::class);

        // A singleton for the same reason the checklist is one: a consumer adds its own rules from a service
        // provider, and a fresh instance per resolution would drop them and then report a clean period.
        //
        // `SuppliesSellerRecords` is deliberately NOT bound here. The seller's own record lives in the
        // consuming application, and the rule that reads it treats an absent binding as a finding rather
        // than as a pass — so an installation that never wired it is told so instead of being told nothing.
        $this->app->singleton(ReportingPlausibilityRules::class);

        // The shipped record format, bound to the contract so a consumer under another duty swaps it for
        // their own rather than switching a foreign one off. It is a complete and deterministic record and
        // deliberately not any authority's wire format — a package that guessed at a schema would produce a
        // file that validates nowhere.
        $this->app->bind(RendersReportingRecord::class, DelimitedSellerRecord::class);

        // VAT-id validation is a seam: the default proves nothing (so the package runs offline and never grants
        // a reverse charge on an unvalidated id), an app that needs real EU B2B zero-rating binds ViesVatIdValidator.
        $this->app->bind(VatIdValidator::class, NullVatIdValidator::class);

        // The small-business register check. A SEPARATE binding from the one above: two registers, two
        // different consequences, and one class answering both would blur the distinction that decides
        // which of them happens. The default contacts nothing, so a bare checkout works offline.
        $this->app->bind(SmallBusinessIdValidator::class, NullSmallBusinessIdValidator::class);

        // The country-from-address signal. The package ships no geolocation data, so the default answers
        // "nothing to say" — a missing input rather than a failure, since other signals answer the same
        // question and one fewer of them is a weaker answer, not a broken one.
        $this->app->bind(IpCountryResolver::class, NullIpCountryResolver::class);

        $this->app->bind(
            DunningNotifier::class,
            LaravelDunningNotifier::class,
        );

        // The escalating suspension warning and the payment-method-removed notice share the default
        // notifier. A no-op late-fee charger is the default; the active driver (Stripe) rebinds it to one
        // that actually raises a fee.
        $this->app->bind(SuspensionNotifier::class, LaravelDunningNotifier::class);
        $this->app->bind(MandateNotifier::class, LaravelDunningNotifier::class);
        $this->app->bind(TrialNotifier::class, LaravelDunningNotifier::class);
        // The receipt (a payment that DID go through) and the cancellation notice with its access-end date
        // — the same default notifier delivers them; an app rebinds either seam on its own.
        $this->app->bind(ReceiptNotifier::class, LaravelDunningNotifier::class);
        $this->app->bind(SubscriptionNotifier::class, LaravelDunningNotifier::class);
        // The quota warning — the customer hears they are running out BEFORE the meter's policy refuses them.
        $this->app->bind(UsageNotifier::class, LaravelDunningNotifier::class);
        $this->app->bind(PaymentActionNotifier::class, LaravelDunningNotifier::class);
        $this->app->bind(LateFees::class, NullLateFees::class);

        $this->app->bind(
            TierCatalog::class,
            ConfigTierCatalog::class,
        );

        $this->app->bind(
            PlanCatalog::class,
            ConfigPlanCatalog::class,
        );

        $this->app->bind(
            AddonCatalog::class,
            ConfigAddonCatalog::class,
        );

        $this->app->bind(
            MerchantCatalog::class,
            SingleMerchantCatalog::class,
        );

        $this->app->bind(
            SubscriptionStateReader::class,
            LocalSubscriptionStateReader::class,
        );

        // The two halves of the content seam a consumer owns, both fail-closed in the direction that matters
        // for what they answer. No subscription covers any work until somebody says which ones do — a default
        // that guessed would hand out the catalog the day the register is switched on. Availability goes the
        // other way: with no catalog wired there is nothing to ask, and reporting every owned work as taken
        // down would be a false alarm about the one thing a buyer is most sensitive to.
        $this->app->bind(
            SubscriptionContentScope::class,
            GrantsNothingBySubscription::class,
        );

        $this->app->bind(
            ContentCatalog::class,
            AssumesEverythingAvailable::class,
        );

        // Neither a work nor a merchant expresses an update preference until a consumer wires this, so every
        // sale falls through to the configured default. Null at each level means "no preference", not a
        // policy — it is what lets the next level answer.
        $this->app->bind(
            UpdatePolicyCatalog::class,
            NoUpdatePolicyPreferences::class,
        );

        // The register is opt-in at the level of "which of your products is a work", not only at the level
        // of a config flag: the shipped map answers "not a work" to everything, so an install that sells
        // credits or seats writes no ownership rows however many one-off purchases go through.
        $this->app->bind(
            AddonContentMap::class,
            NoAddonContent::class,
        );

        // Same direction for bundles. A package that guessed at bundle membership would hand out works
        // nobody grouped together, so every bundle is empty until a consumer says otherwise.
        $this->app->bind(
            BundleContents::class,
            NoBundles::class,
        );

        // And the version list, for the same reason. Left unbound the contract was findable in the docs and
        // unusable in an app — resolving it threw. An empty list produces the pre-order shape downstream,
        // which the model already expresses, so nothing has to special-case the absence.
        $this->app->bind(
            ContentVersions::class,
            NoContentVersions::class,
        );

        // This is what makes the switch load-bearing. Off, the seam resolves to a reader that answers no to
        // everything — an answer, not a resolution error, so "off" and "miswired" stay distinguishable at the
        // call site.
        $this->app->bind(
            ContentAccessReader::class,
            fn (Application $app): ContentAccessReader => $app->make('config')->get('billing.content_ownership.enabled') === true
                ? $app->make(DatabaseContentAccessReader::class)
                : $app->make(DisabledContentAccessReader::class),
        );

        $this->app->bind(
            BillingEntityResolver::class,
            ConfigBillingEntityResolver::class,
        );

        // The default tier resolver reads the denormalized tier column (config('billing.tier_column')).
        // An app that does NOT keep a tier column rebinds this to SubscriptionTierResolver (maps the
        // active price back to a tier) in one line. Without a default, the very first metered install
        // threw a BindingResolutionException on app(UsageRecorder::class)->record(...).
        $this->app->bind(
            TierResolver::class,
            ColumnTierResolver::class,
        );

        // Once a tier declares metered components, usage is read from the package's own counters, so
        // what the usage screen shows is exactly what the owner is billed for. An app that meters
        // nothing keeps the unmetered provider — and, with it, no dependency on a TierResolver it never
        // had to bind. Either way an app with its own metering source still rebinds this.
        $this->app->bind(
            UsageProvider::class,
            fn (Application $app): UsageProvider => $app->make(MeterCatalog::class)->meterKeys() === []
                ? $app->make(NullUsageProvider::class)
                : $app->make(CounterUsageProvider::class),
        );

        // Past usage for the UsageHistory screen reads the persisted counters column-authoritatively; a
        // project may bind its own to source history elsewhere.
        $this->app->bind(UsageHistoryProvider::class, DatabaseUsageHistory::class);

        // A driver that cannot meter refuses to report rather than silently billing nothing. The Stripe
        // driver replaces this with its own reporter; the boot guard makes sure a metered tier never
        // runs on a driver that is stuck with this one.
        $this->app->bind(UsageReporter::class, NullUsageReporter::class);

        // Meter verification defaults to "no remote meters" — right for a local-engine driver, which never
        // carries a metered tier past the boot guard. The Stripe driver replaces this with a real inspector.
        $this->app->bind(MeterInspector::class, NullMeterInspector::class);

        // Seat billing defaults to "no seats at the provider" — right for a user-owner app and any driver
        // that does not bill by seat. The Stripe driver replaces this with a real seam.
        $this->app->bind(SeatBilling::class, NullSeatBilling::class);

        // The suspension ladder locks delinquent owners out of configured surfaces (423).
        $this->app->bind(SuspensionLadder::class, LadderSuspension::class);

        // The read-only dunning gate: a consumer resolves it to gate a feature on an owner's dunning state
        // (blockingState() is null when nothing blocks). Driver-independent — it reads only the local row.
        $this->app->bind(DunningGuard::class, LocalDunningGuard::class);

        // Entitlement grants (what a tier unlocks) read live from the separate license config.
        $this->app->bind(License::class, ConfigLicense::class);

        // E-invoicing: the dependency-free XRechnung/UBL writer is the baseline (ZUGFeRD is opt-in).
        $this->app->bind(EInvoice::class, XRechnungInvoice::class);

        // Eligibility (age/KYC) is project-specific, so money flows by default; an app gates it by
        // binding the fail-closed ComposedEligibilityGate with its own checks.
        $this->app->bind(CanTransactMoney::class, AlwaysEligible::class);

        // ONE read of the shipped rate file for the whole application. It is a singleton rather than a
        // per-resolution load because the digest is verified on every load, and hashing a table on every
        // invoice would be a real cost for a file that cannot change while the process runs.
        $this->app->singleton(ShippedTaxRates::class, static fn (): ShippedTaxRates => ShippedTaxRates::shipped());

        $this->app->bind(
            TaxCalculator::class,
            static fn (Application $app): TaxCalculator => new TaxCalculatorFactory(
                $app->make(Repository::class),
                $app->make(CheckpointRegistry::class),
                $app->make(ShippedTaxRates::class),
            )->make(),
        );

        // The place resolver needs two facts it cannot invent: where the seller is, and which countries
        // share the seller's tax union. Auto-wired it would take null for both — and null seller country
        // means cross-border can never be proven, so a validated business elsewhere would be charged
        // domestic tax and a seller-placed supply would be taxed at the buyer. Neither raises anything.
        $this->app->bind(
            PlaceOfSupplyResolver::class,
            static function (Application $app): PlaceOfSupplyResolver {
                $country = $app->make(Repository::class)->get('billing.company.country');
                $profile = $app->make(CheckpointRegistry::class)->profile();

                return new PlaceOfSupplyResolver(
                    is_string($country) && $country !== '' ? $country : null,
                    $profile instanceof DefinesUnionMembership ? $profile->unionMembers() : null,
                );
            },
        );

        // The proration strategy a driver falls back to, bound HERE and BEFORE the driver below — the
        // order is the whole mechanism, because the driver's own bind() replaces this one.
        //
        // It called itself the package default in two docblocks and nothing bound it. On a Stripe install
        // that is invisible: the driver binds its own strategy a few lines down and the account hub gets a
        // preview. Anywhere else — the disabled clone, a consumer who swapped the driver, the local engines
        // this package is being built toward — `ProrationStrategy` is an interface with no implementation,
        // and the container cannot build one. The swap preview does not degrade; it throws.
        //
        // Which is the wrong failure for what this strategy says: previewing a swap is a nicety, and the
        // honest answer where a provider prorates on its own side is "no local figure" rather than an
        // exception in the middle of a subscription screen.
        $this->app->bind(ProrationStrategy::class, DelegatedProrationStrategy::class);

        // WHO publishes the rates this installation imports. Shipped bound to the central bank, which is
        // where the importer's URL and its 'ECB' literal used to live — so an installation that never
        // thinks about publishers keeps exactly the series it had, and one filing under another
        // jurisdiction's rule can replace the identity without touching the command.
        $this->app->bind(PublishesExchangeRates::class, EcbRatePublisher::class);

        // The shipped default driver registers its own bindings (the Stripe SDK
        // client, the driver factory, and the account-hub/webhook contracts). The
        // future local-engine drivers ship their own providers alongside it.
        $this->app->register(StripeServiceProvider::class);

        // The first LOCAL-ENGINE driver, registered alongside rather than instead: `extend()` costs nothing
        // and must not depend on configuration, or an install resolving `driver('mollie')` explicitly would
        // be told it does not exist. Its rebinds are conditional on it being the active driver.
        $this->app->register(MollieServiceProvider::class);

        // No-op façade for a billing-disabled clone. The driver above binds the invoice / subscription
        // contracts to its Stripe impls unconditionally, so with the master switch off we rebind them to safe
        // no-ops: reads answer empty/null, mutations do nothing, and nothing reaches for Stripe keys the clone
        // does not have. (UsageProvider and TierResolver are already provider-free, so they need no rebind.)
        if (! (bool) $this->app->make(Repository::class)->get('billing.enabled', true)) {
            $this->app->bind(SubscriptionActions::class, NullSubscriptionActions::class);
            $this->app->bind(UpcomingInvoice::class, NullUpcomingInvoice::class);
            $this->app->bind(Invoices::class, NullInvoices::class);
        }
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'billing');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'billing');
        $this->loadRoutesFrom(__DIR__.'/../routes/billing.php');

        if (self::$runsMigrations) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations/server');
        }

        // The account hub is part of the master switch: when billing is off, the SCREENS and their routes do
        // not exist at all (a clean no-op clone).
        //
        // The two WEBHOOK routes are the exception, and it is deliberate rather than an oversight in the
        // ordering above. `loadRoutesFrom()` runs unconditionally, so `billing/webhook` and
        // `billing/webhook/marketplace` stay MOUNTED with billing off — and both receivers answer 404 in
        // that state, which is where the switch is actually honored. A provider whose endpoint 404s backs
        // off and retries; one whose endpoint stops resolving is a delivery that fails differently, and an
        // installation that switches billing off for a week should not have to re-register with its
        // provider afterwards.
        //
        // Said explicitly because the sentence above used to claim it for every route, and a consumer read
        // that literally: these two endpoints carry no CSRF middleware (the verifier authenticates by
        // signature instead), so "the routes do not exist" is exactly the kind of promise somebody stops
        // checking. `MasterSwitchLeavesOnlyTheWebhooksTest` holds the real answer.
        if ((bool) $this->app->make(Repository::class)->get('billing.enabled', true)) {
            // A tier that bills for usage on a driver that cannot report it would count every unit and
            // invoice none of them. Refuse to boot instead.
            $this->app->make(MeteringSupportGuard::class)->verify();

            // A local tax mode (eu_oss) on a driver that defers the charge to the provider would compute VAT
            // the provider never collects — a silent under-charge. Refuse to boot instead.
            $this->app->make(TaxSupportGuard::class)->verify();

            // A self-contradictory config (zero_tier not in tiers, a tier pointing at an unknown dimension,
            // dunning rungs out of order) would mis-tier a customer or break a screen silently. Fail loud.
            $this->app->make(BillingConfigValidator::class)->validate();

            // Refuse to boot on a financial-record retention window below the statutory floor: a window set
            // too short would prune tax records too early. EU law leads; keeping data longer is always fine.
            $this->app->make(RetentionFloorGuard::class)->verify();

            // Refuse to boot the platform-held custody mode without a license attestation: holding other
            // people's funds on the platform's own account is a regulated activity, and a config flag alone
            // must never be enough to enable it. A no-op unless the marketplace is on.
            $this->app->make(CustodyGuard::class)->verify();

            // Refuse to boot a marketplace whose driver cannot route money. Read in boot(), never in
            // register(): a driver provider binds at register() time, and a config read there runs before
            // the test harness has applied the environment's config.
            $this->app->make(MarketplaceSupportGuard::class)->verify();

            // Refuse to boot a marketplace whose go-live checklist still has open blocking points. It runs
            // AFTER the support guard on purpose: a driver that cannot route money is one specific fault
            // with one specific fix, and it deserves its own message rather than a line in a checklist.
            $this->app->make(GoLivePreflightGuard::class)->verify();

            // Refuse to boot with a market opened that the local rates cannot price. That combination is
            // dangerous because neither half looks wrong: the market is open, the calculator answers, and
            // the answer is zero — a sale that carries no tax and reports no fault. Checked here so it
            // surfaces on a deploy rather than on somebody's invoice.
            //
            // Only against the LOCAL rate table. Under a provider-side tax mode there is nothing here to
            // compare with, and a check that ran anyway would be reporting on a subject it cannot see.
            $this->verifyOpenMarketsArePriced();

            // The account hub is an OPTIONAL Livewire/WireKit UI: livewire is a suggest + require-dev, not a
            // hard dependency. Register the nine screens and their routes only when Livewire is installed —
            // the billing core (models, webhooks, invoicing, tax, contracts) never needs it, and CheckoutUrls
            // falls back to configured URLs when the hub's own routes are absent.
            if (class_exists(Livewire::class)) {
                $this->registerAccountHub();
                // The optional admin console — a separate, admin-gated route, NOT one of the account-hub
                // screens. Same Livewire-only condition; the billing core never needs it.
                $this->registerAdminConsole();
            }

            // The per-surface suspension lockout, applied by the host as
            // ->middleware('billing.suspend:<surface>').
            $router = $this->app->make(Router::class);
            $router->aliasMiddleware('billing.suspend', EnforceSuspension::class);
            // The metered-quota gate, applied as ->middleware('billing.quota:<meter>') (optionally
            // ',<units>') to refuse a request that would take the owner past a blocking allowance.
            $router->aliasMiddleware('billing.quota', EnforceQuota::class);
            // The hard-dunning gate, applied as ->middleware('billing.dunning'): a past-due owner is sent
            // to the payment-recovery screen (browser) or gets a 402 (API). Never put it on the recovery
            // route itself.
            $router->aliasMiddleware('billing.dunning', EnforceDunning::class);

            // Re-sync a team's billed seats whenever its membership changes. The consumer names its own
            // join/leave events; each one drives the queued seat-sync listener.
            $this->registerSeatSyncListeners();

            // Stop live billing the instant an account is being deleted — a deleted owner must never linger
            // as an active, still-charging subscription at the provider. An app dispatches
            // BillableAccountDeleting from its own delete flow; the package's BillingEraser dispatches it too.
            $this->app->make(Dispatcher::class)->listen(BillableAccountDeleting::class, StopBillingForDeletedAccount::class);

            // Tell a merchant when the platform changed their tax standing without being asked. The change
            // is not a preference and neither is the notice: it alters what they owe and what their own
            // documents must say, from a date they never saw. Unheard, they keep filing as they were and
            // hear about it from an authority.
            $this->app->make(Dispatcher::class)->listen(
                CreatorTaxStatusChanged::class,
                NotifyMerchantOfAutomaticTaxStatusChange::class,
            );
        }

        if ($this->app->runningInConsole()) {
            $this->registerPublishing();
            $this->commands([
                InstallCommand::class,
                BillingRunCommand::class,
                FlushUsageCommand::class,
                WarnExpiringCardsCommand::class,
                WarnEndingTrialsCommand::class,
                AdvanceBuyerProtectionCommand::class,
                AdvanceDunningCommand::class,
                TaxReturnExportCommand::class,
                RecordMarketAccessCommand::class,
                SyncSubscriptionsCommand::class,
                ReplayWebhooksCommand::class,
                EraseOwnerCommand::class,
                ExportOwnerCommand::class,
                PruneBillingCommand::class,
                DoctorCommand::class,
                ReleaseAbandonedClaimCommand::class,
                AnnounceLapsedAttestationsCommand::class,
                ExpireDelinquentSubscriptionsCommand::class,
                RemindDelinquentSubscriptionsCommand::class,
                WarnUnestablishedStandingsCommand::class,
                AnnounceUpcomingFilingsCommand::class,
                AnnounceVoucherVolumeCommand::class,
                ImportExchangeRatesCommand::class,
                FreezeReportingRatesCommand::class,
                ReconcileTaxStatusCommand::class,
                ProbeRatesCommand::class,
                CheckTaxRatesCommand::class,
                CheckMetersCommand::class,
                ReconcileMerchantJournalCommand::class,
                ReconcileUsageCommand::class,
                DatevExportCommand::class,
                CancelSubscriptionCommand::class,
                GrantTierCommand::class,
                MarketplacePreflightCommand::class,
                MerchantOnboardCommand::class,
                MerchantReopenCommand::class,
                MerchantStatusCommand::class,
                RefreshMerchantCapabilitiesCommand::class,
                ReportingRunCommand::class,
                ReportingFileCommand::class,
            ]);
        }

        // The local-engine cycle advance. A no-op under Stripe; the local engine advances due
        // subscriptions here. Deferred until the scheduler resolves so it costs nothing otherwise.
        //
        // The usage flush runs on its own, far tighter cadence: it hands recorded usage to the provider,
        // and usage that has not reached the provider by the time the cycle's invoice closes is revenue
        // that will not be collected. withoutOverlapping, because two flushers racing the same outbox is
        // how the same units get reported under two identifiers.
        // Captured rather than reached for with the `config()` helper: that helper is Foundation-only, and
        // this package requires focused illuminate/* components instead of the framework. The repository is
        // the live singleton, so a value set after boot is still the value read when the scheduler resolves.
        $scheduleConfig = $this->app->make(Repository::class);

        $heartbeat = $this->app;

        /**
         * Attach the heartbeat to a scheduled entry.
         *
         * Resolved lazily inside the callbacks rather than captured, so an install that binds its own
         * implementation after this provider booted still gets it — and so the default costs nothing.
         *
         * The `before` signal is the one that matters. A command that fails is loud; a command that stops
         * RUNNING is not, and only something outside the process noticing an expected ping did not arrive
         * can catch it.
         */
        $withHeartbeat = (static fn (Event $event, string $command): Event => $event
            ->before(static fn () => $heartbeat->make(ScheduleHeartbeat::class)->starting($command))
            ->onSuccess(static fn () => $heartbeat->make(ScheduleHeartbeat::class)->finished($command, true))
            ->onFailure(static fn () => $heartbeat->make(ScheduleHeartbeat::class)->finished($command, false)));

        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule) use ($scheduleConfig, $withHeartbeat): void {
            // withoutOverlapping like the others: a local-engine cycle advance that runs long must not have
            // a second copy start on top of it and double-advance the same due subscriptions.
            $withHeartbeat($schedule->command('billing:run')->hourly()->withoutOverlapping(), 'billing:run');
            $schedule->command('billing:usage:flush')->everyMinute()->withoutOverlapping();
            // A daily proactive nudge before a card expires — the biggest preventable cause of churn.
            $schedule->command('billing:cards:warn')->dailyAt('09:00')->withoutOverlapping();
            // The same nudge for the trial that has no provider to send one. A subscription trial ends with a
            // provider event; the GENERIC trial is a date on the owner's own row and nothing could ever
            // announce it — which made the mode WITHOUT a card, the one where the customer has no other
            // signal, the one that ended in silence.
            $schedule->command('billing:trials:warn')->dailyAt('09:05')->withoutOverlapping();
            // The tax-standing deadline, announced BEFORE it bites. Daily and early, because the value of
            // this message is entirely in how much time it leaves: a merchant needs longer to produce a
            // declaration than a checkout takes to fail. It is silent until an operator sets the date.
            $schedule->command('billing:tax-holds:warn')->dailyAt('09:15')->withoutOverlapping();
            // A daily walk up the dunning ladder — escalating warnings + fees for delinquent owners.
            $schedule->command('billing:dunning:advance')->dailyAt('09:15')->withoutOverlapping();
            // The cure-window half of that ladder, and the half that ENDS things. A payment failing writes a
            // row somebody can watch; a day passing inside the window writes nothing at all, so without these
            // two the customer hears once — when the payment failed — and then again only when the
            // subscription is gone. The reminder runs first and the expiry after it, so a window that ends
            // today produces the final notice rather than a countdown that stops at zero. Both select
            // merchant-scoped rows only, so a single-seller install pays two empty queries a day.
            $schedule->command('billing:dunning:remind')->dailyAt('09:30')->withoutOverlapping();
            $schedule->command('billing:dunning:expire')->dailyAt('09:45')->withoutOverlapping();
            // The retention clock. Personal data the package no longer needs is not data it may keep.
            $schedule->command('billing:prune')->dailyAt('03:30')->withoutOverlapping();
            // The drift guard: read the provider's own totals back and compare, and alarm on a backlog held
            // past the point it can still be billed. The flush is quiet about both by design — this is the
            // daily check that surfaces revenue quietly going uncollected.
            $schedule->command('billing:usage:reconcile')->dailyAt('04:00')->withoutOverlapping();
            // The one hold nothing else can notice. A merchant whose attestation expires is stopped from
            // selling and from being paid WITHOUT a row changing anywhere — the date simply passed. Without
            // this sweep they find out by trying to sell. Early, so the notice lands before their day does.
            $schedule->command('billing:tax-holds:announce')->dailyAt('06:00')->withoutOverlapping();
            // The filing obligations, announced before their day. Daily, because the notice window is
            // measured in days and a weekly sweep would land inside it by chance rather than by design.
            $schedule->command('billing:filings:announce')->dailyAt('06:15')->withoutOverlapping();
            // The voucher-volume levels, and the only entry here that is registered CONDITIONALLY. Vouchers
            // are off by default and the whole feature waits on a legal question; a schedule entry that ran
            // anyway would query an empty table every morning on every install that never issued a voucher.
            // Gated at registration rather than skipped inside the command, so an operator reading
            // `schedule:list` sees what actually runs for them instead of a line that always no-ops.
            if ((bool) $scheduleConfig->get('billing.marketplace.vouchers.enabled', false)) {
                $schedule->command('billing:vouchers:volume')->dailyAt('06:30')->withoutOverlapping();
            }
            // The other event nothing can observe, and this one DECIDES rather than announces. A creator
            // crossing a turnover limit writes no row: enough sales accumulate and a threshold is simply
            // past, so the moment the flip should have happened is a moment nothing dispatched. Left
            // unrun, a creator who has outgrown their relief keeps issuing tax-free documents, which is
            // knowingly wrong from the breaking sale onward. Just after the announcement, so a standing
            // written here is in place before the next day's selling rather than mid-morning.
            $schedule->command('billing:tax-status:reconcile')->dailyAt('06:15')->withoutOverlapping();
            // The central bank publishes its daily reference rates around 16:00 CET, so anything earlier
            // would fetch a day that does not exist yet and quietly import nothing for it. Off unless the
            // local rate store is switched on AND currencies are listed — the command itself checks both
            // and says which one stopped it, rather than contacting anybody by default.
            $schedule->command('billing:exchange-rates:import')->dailyAt('17:30')->withoutOverlapping();
            // The buyer-protection clock. Its two deadlines -- the buyer's silence turning into consent, and
            // the absolute decision date -- are DATES, and a date only means something if something reads it.
            // Unscheduled, the hold simply waits until the provider stops waiting and pays out anyway: the
            // money arrives, so nothing looks broken, and only the promise behind it was empty. The command
            // said "Meant to run daily" in its own docblock while nothing ran it.
            //
            // Safe to schedule unconditionally: with no holds it moves nothing and exits zero, so an install
            // that never enables buyer protection pays one empty query a day.
            $schedule->command('billing:protection:advance')->dailyAt('05:00')->withoutOverlapping();
        });
    }

    /** Register the account-hub Livewire screens and their config-driven routes. */
    private function registerAccountHub(): void
    {
        Livewire::component('billing.account-overview', AccountOverview::class);
        Livewire::component('billing.account-realtime', AccountRealtime::class);
        Livewire::component('billing.subscription-overview', SubscriptionOverview::class);
        Livewire::component('billing.manage-subscription', ManageSubscription::class);
        Livewire::component('billing.invoice-history', InvoiceHistory::class);
        Livewire::component('billing.payment-method-manager', PaymentMethodManager::class);
        Livewire::component('billing.usage-overview', UsageOverview::class);
        Livewire::component('billing.usage-history', UsageHistory::class);
        Livewire::component('billing.payment-recovery', PaymentRecovery::class);
        Livewire::component('billing.danger-zone', DangerZone::class);

        // The app-shell banner — a plain Blade component the host drops into its layout.
        Blade::component('billing::banner', Banner::class);

        $config = $this->app->make(Repository::class);
        $prefix = $config->get('account.prefix', 'account/billing');
        $middleware = $config->get('account.middleware', ['web', 'auth']);
        $middleware = is_array($middleware) ? $middleware : ['web', 'auth'];

        // The scoped CSP always applies to the hub, whatever auth stack the app configures, so the
        // payment element's origins are whitelisted here and nowhere else.
        $middleware[] = AccountContentSecurityPolicy::class;

        Route::middleware($middleware)
            ->prefix(is_string($prefix) ? $prefix : 'account/billing')
            ->group(function (): void {
                Route::get('/', AccountOverview::class)->name('billing.account.overview');
                Route::get('/subscription', SubscriptionOverview::class)->name('billing.account.subscription');
                Route::get('/plan', ManageSubscription::class)->name('billing.account.plan');
                Route::get('/invoices', InvoiceHistory::class)->name('billing.account.invoices');
                // A document download is heavier than a screen render and worth rate-limiting; throttle it
                // per the framework's limiter (60/min). The controller marks the response noindex.
                Route::get('/invoices/{invoiceId}/download', [BillingController::class, 'downloadInvoice'])
                    ->middleware('throttle:60,1')
                    ->name('billing.account.invoice-download');
                Route::get('/payment-methods', PaymentMethodManager::class)->name('billing.account.payment-methods');
                Route::get('/usage', UsageOverview::class)->name('billing.account.usage');
                Route::get('/usage/history', UsageHistory::class)->name('billing.account.usage-history');
                Route::get('/recovery', PaymentRecovery::class)->name('billing.account.recovery');
                Route::get('/danger', DangerZone::class)->name('billing.account.danger');
                Route::get('/portal', [BillingController::class, 'portal'])->name('billing.account.portal');
                Route::get('/checkout/return', [BillingController::class, 'checkoutReturn'])->name('billing.account.checkout-return');
            });
    }

    /**
     * Register the optional admin console: one Livewire component on its own admin-prefixed route. It is
     * NOT an account-hub screen — it has a stricter gate (the console authorizes every request against the
     * app-defined `billing.admin.ability`, fail-closed) and its own minimal shell, so it stays clear of the
     * customer account screens and their security harness.
     */
    private function registerAdminConsole(): void
    {
        Livewire::component('billing.admin-console', BillingAdminConsole::class);

        $config = $this->app->make(Repository::class);
        $prefix = $config->get('billing.admin.prefix', 'admin/billing');
        $middleware = $config->get('billing.admin.middleware', ['web', 'auth']);
        $middleware = is_array($middleware) ? $middleware : ['web', 'auth'];

        Route::middleware($middleware)
            ->prefix(is_string($prefix) ? $prefix : 'admin/billing')
            ->group(function (): void {
                Route::get('/', BillingAdminConsole::class)->name('billing.admin.console');
            });
    }

    /**
     * Bind the queued seat-sync listener to every membership event the consumer configured. The package does
     * not own those events, so nothing fires until a consumer names them in `billing.seats.membership_events`.
     * Called from boot(); public so the wiring can be verified directly without a second full boot.
     */
    public function registerSeatSyncListeners(): void
    {
        $events = $this->app->make(Repository::class)->get('billing.seats.membership_events', []);
        $dispatcher = $this->app->make(Dispatcher::class);

        foreach (is_array($events) ? $events : [] as $event) {
            if (is_string($event) && $event !== '') {
                $dispatcher->listen($event, SyncSeatsOnMembershipChange::class);
            }
        }
    }

    private function registerPublishing(): void
    {
        // Each group carries its own specific tag AND the shared `billing` umbrella tag, so a consumer can
        // publish one asset kind (`--tag=billing-config`) or everything at once (`--tag=billing`).
        $this->publishes([
            __DIR__.'/../config/billing.php' => $this->app->configPath('billing.php'),
            __DIR__.'/../config/account.php' => $this->app->configPath('account.php'),
            __DIR__.'/../config/license.php' => $this->app->configPath('license.php'),
        ], ['billing', 'billing-config']);

        // publishesMigrations (not publishes) rewrites each file with a fresh, monotonically increasing
        // timestamp at publish time, so a consumer who publishes them never gets a dev-era prefix that could
        // sort before one of their own migrations. The package still loadMigrationsFrom() the same directory
        // for the zero-config case; a consumer who publishes should stop the auto-load with
        // BillingServiceProvider::ignoreMigrations() to avoid running both copies.
        $this->publishesMigrations([
            __DIR__.'/../database/migrations/server' => $this->app->databasePath('migrations'),
        ], ['billing', 'billing-migrations']);

        $this->publishes([
            __DIR__.'/../resources/views' => $this->app->resourcePath('views/vendor/billing'),
        ], ['billing', 'billing-views']);

        $this->publishes([
            __DIR__.'/../lang' => $this->app->langPath('vendor/billing'),
        ], ['billing', 'billing-lang']);
    }

    /**
     * Every opened market must be one the local rates can price.
     *
     * Scoped to the EU-OSS mode on purpose: it is the only mode with a rate table of its own. A consumer
     * whose rates come from the provider legitimately opens markets this package knows nothing about, and
     * refusing those would be a guard enforcing a limit it invented.
     */
    private function verifyOpenMarketsArePriced(): void
    {
        $config = $this->app->make(Repository::class);

        if ($config->get('billing.tax') !== 'eu_oss') {
            return;
        }

        $country = $config->get('billing.company.country');

        $this->app->make(MarketAllowlist::class)->assertEveryOpenMarketIsPriced(
            new EuOssTaxCalculator(is_string($country) ? $country : null, shipped: $this->app->make(ShippedTaxRates::class))->knowsRateFor(...),
        );
    }
}
