<?php

declare(strict_types=1);

namespace Pushery\Billing;

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
use Pushery\Billing\Catalogs\ConfigPlanCatalog;
use Pushery\Billing\Catalogs\ConfigTierCatalog;
use Pushery\Billing\Catalogs\MeterCatalog;
use Pushery\Billing\Console\Commands\AdvanceDunningCommand;
use Pushery\Billing\Console\Commands\BillingRunCommand;
use Pushery\Billing\Console\Commands\CheckMetersCommand;
use Pushery\Billing\Console\Commands\DatevExportCommand;
use Pushery\Billing\Console\Commands\DoctorCommand;
use Pushery\Billing\Console\Commands\EraseOwnerCommand;
use Pushery\Billing\Console\Commands\ExportOwnerCommand;
use Pushery\Billing\Console\Commands\FlushUsageCommand;
use Pushery\Billing\Console\Commands\GrantTierCommand;
use Pushery\Billing\Console\Commands\InstallCommand;
use Pushery\Billing\Console\Commands\PruneBillingCommand;
use Pushery\Billing\Console\Commands\ReconcileUsageCommand;
use Pushery\Billing\Console\Commands\ReplayWebhooksCommand;
use Pushery\Billing\Console\Commands\SyncSubscriptionsCommand;
use Pushery\Billing\Console\Commands\WarnExpiringCardsCommand;
use Pushery\Billing\Contracts\BillingEntityResolver;
use Pushery\Billing\Contracts\CanTransactMoney;
use Pushery\Billing\Contracts\CreditSync;
use Pushery\Billing\Contracts\CustomerRegistry;
use Pushery\Billing\Contracts\DiscountResolver;
use Pushery\Billing\Contracts\DunningGuard;
use Pushery\Billing\Contracts\DunningNotifier;
use Pushery\Billing\Contracts\EInvoice;
use Pushery\Billing\Contracts\LateFees;
use Pushery\Billing\Contracts\License;
use Pushery\Billing\Contracts\MandateNotifier;
use Pushery\Billing\Contracts\MeterInspector;
use Pushery\Billing\Contracts\PaymentActionNotifier;
use Pushery\Billing\Contracts\PlanCatalog;
use Pushery\Billing\Contracts\ReceiptNotifier;
use Pushery\Billing\Contracts\SeatBilling;
use Pushery\Billing\Contracts\SubscriptionNotifier;
use Pushery\Billing\Contracts\SuspensionLadder;
use Pushery\Billing\Contracts\SuspensionNotifier;
use Pushery\Billing\Contracts\TaxCalculator;
use Pushery\Billing\Contracts\TierCatalog;
use Pushery\Billing\Contracts\TierResolver;
use Pushery\Billing\Contracts\TrialNotifier;
use Pushery\Billing\Contracts\UsageHistoryProvider;
use Pushery\Billing\Contracts\UsageNotifier;
use Pushery\Billing\Contracts\UsageProvider;
use Pushery\Billing\Contracts\UsageReporter;
use Pushery\Billing\Discounts\ConfigDiscountResolver;
use Pushery\Billing\Drivers\NullCreditSync;
use Pushery\Billing\Drivers\NullCustomerRegistry;
use Pushery\Billing\Drivers\NullMeterInspector;
use Pushery\Billing\Drivers\NullSeatBilling;
use Pushery\Billing\Drivers\Stripe\StripeServiceProvider;
use Pushery\Billing\Dunning\LadderSuspension;
use Pushery\Billing\Dunning\LocalDunningGuard;
use Pushery\Billing\Dunning\NullLateFees;
use Pushery\Billing\Eligibility\AlwaysEligible;
use Pushery\Billing\Entitlements\ConfigLicense;
use Pushery\Billing\Events\BillableAccountDeleting;
use Pushery\Billing\Http\Controllers\BillingController;
use Pushery\Billing\Http\Middleware\AccountContentSecurityPolicy;
use Pushery\Billing\Http\Middleware\EnforceDunning;
use Pushery\Billing\Http\Middleware\EnforceQuota;
use Pushery\Billing\Http\Middleware\EnforceSuspension;
use Pushery\Billing\Invoicing\XRechnungInvoice;
use Pushery\Billing\Listeners\StopBillingForDeletedAccount;
use Pushery\Billing\Listeners\SyncSeatsOnMembershipChange;
use Pushery\Billing\Livewire\AccountOverview;
use Pushery\Billing\Livewire\DangerZone;
use Pushery\Billing\Livewire\InvoiceHistory;
use Pushery\Billing\Livewire\ManageSubscription;
use Pushery\Billing\Livewire\PaymentMethodManager;
use Pushery\Billing\Livewire\PaymentRecovery;
use Pushery\Billing\Livewire\SubscriptionOverview;
use Pushery\Billing\Livewire\UsageHistory;
use Pushery\Billing\Livewire\UsageOverview;
use Pushery\Billing\Notifiers\LaravelDunningNotifier;
use Pushery\Billing\Resolvers\ColumnTierResolver;
use Pushery\Billing\Resolvers\ConfigBillingEntityResolver;
use Pushery\Billing\Support\BillingConfigValidator;
use Pushery\Billing\Support\BillingManager;
use Pushery\Billing\Support\MeteringSupportGuard;
use Pushery\Billing\Support\RetentionFloorGuard;
use Pushery\Billing\Support\TaxSupportGuard;
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

        $this->app->bind(DiscountResolver::class, ConfigDiscountResolver::class);

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

        $this->app->bind(
            TaxCalculator::class,
            static fn (Application $app): TaxCalculator => new TaxCalculatorFactory(
                $app->make(Repository::class),
            )->make(),
        );

        // The shipped default driver registers its own bindings (the Stripe SDK
        // client, the driver factory, and the account-hub/webhook contracts). The
        // future Mollie/Adyen drivers ship their own providers alongside it.
        $this->app->register(StripeServiceProvider::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'billing');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'billing');
        $this->loadRoutesFrom(__DIR__.'/../routes/billing.php');

        if (self::$runsMigrations) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations/server');
        }

        // The account hub is part of the master switch: when billing is off, the
        // screens and their routes do not exist at all (a clean no-op clone).
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

            // The account hub is an OPTIONAL Livewire/WireKit UI: livewire is a suggest + require-dev, not a
            // hard dependency. Register the nine screens and their routes only when Livewire is installed —
            // the billing core (models, webhooks, invoicing, tax, contracts) never needs it, and CheckoutUrls
            // falls back to configured URLs when the hub's own routes are absent.
            if (class_exists(Livewire::class)) {
                $this->registerAccountHub();
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
        }

        if ($this->app->runningInConsole()) {
            $this->registerPublishing();
            $this->commands([
                InstallCommand::class,
                BillingRunCommand::class,
                FlushUsageCommand::class,
                WarnExpiringCardsCommand::class,
                AdvanceDunningCommand::class,
                SyncSubscriptionsCommand::class,
                ReplayWebhooksCommand::class,
                EraseOwnerCommand::class,
                ExportOwnerCommand::class,
                PruneBillingCommand::class,
                DoctorCommand::class,
                CheckMetersCommand::class,
                ReconcileUsageCommand::class,
                DatevExportCommand::class,
                GrantTierCommand::class,
            ]);
        }

        // The local-engine cycle advance. A no-op under Stripe; the Mollie/Adyen engine advances due
        // subscriptions here. Deferred until the scheduler resolves so it costs nothing otherwise.
        //
        // The usage flush runs on its own, far tighter cadence: it hands recorded usage to the provider,
        // and usage that has not reached the provider by the time the cycle's invoice closes is revenue
        // that will not be collected. withoutOverlapping, because two flushers racing the same outbox is
        // how the same units get reported under two identifiers.
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            // withoutOverlapping like the others: a local-engine cycle advance that runs long must not have
            // a second copy start on top of it and double-advance the same due subscriptions.
            $schedule->command('billing:run')->hourly()->withoutOverlapping();
            $schedule->command('billing:usage:flush')->everyMinute()->withoutOverlapping();
            // A daily proactive nudge before a card expires — the biggest preventable cause of churn.
            $schedule->command('billing:cards:warn')->dailyAt('09:00')->withoutOverlapping();
            // A daily walk up the dunning ladder — escalating warnings + fees for delinquent owners.
            $schedule->command('billing:dunning:advance')->dailyAt('09:15')->withoutOverlapping();
            // The retention clock. Personal data the package no longer needs is not data it may keep.
            $schedule->command('billing:prune')->dailyAt('03:30')->withoutOverlapping();
            // The drift guard: read the provider's own totals back and compare, and alarm on a backlog held
            // past the point it can still be billed. The flush is quiet about both by design — this is the
            // daily check that surfaces revenue quietly going uncollected.
            $schedule->command('billing:usage:reconcile')->dailyAt('04:00')->withoutOverlapping();
        });
    }

    /** Register the account-hub Livewire screens and their config-driven routes. */
    private function registerAccountHub(): void
    {
        Livewire::component('billing.account-overview', AccountOverview::class);
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
        $this->publishes([
            __DIR__.'/../config/billing.php' => config_path('billing.php'),
            __DIR__.'/../config/account.php' => config_path('account.php'),
            __DIR__.'/../config/license.php' => config_path('license.php'),
        ], 'billing-config');

        // publishesMigrations (not publishes) rewrites each file with a fresh, monotonically increasing
        // timestamp at publish time, so a consumer who publishes them never gets a dev-era prefix that could
        // sort before one of their own migrations. The package still loadMigrationsFrom() the same directory
        // for the zero-config case; a consumer who publishes should stop the auto-load with
        // BillingServiceProvider::ignoreMigrations() to avoid running both copies.
        $this->publishesMigrations([
            __DIR__.'/../database/migrations/server' => database_path('migrations'),
        ], 'billing-migrations');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/billing'),
        ], 'billing-views');

        $this->publishes([
            __DIR__.'/../lang' => lang_path('vendor/billing'),
        ], 'billing-lang');
    }
}
