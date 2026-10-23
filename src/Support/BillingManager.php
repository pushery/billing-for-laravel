<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Contracts\BillingDriver;
use Pushery\Billing\Contracts\EnabledPaymentMethods;
use Pushery\Billing\Contracts\MarketplaceRails;
use Pushery\Billing\Contracts\PaymentCsp;
use Pushery\Billing\Contracts\RoutesMoney;
use Pushery\Billing\Drivers\NullDriver;
use Pushery\Billing\Exceptions\MarketplaceUnsupported;
use Pushery\Billing\Exceptions\UnsupportedDriver;
use Pushery\Billing\ValueObjects\DriverCapabilities;
use Pushery\Billing\ValueObjects\MerchantScope;
use Pushery\Billing\ValueObjects\PaymentAccount;
use Pushery\Billing\Webhooks\AccountWebhooks;

/**
 * Resolves the active payment driver by name and honors the master switch: when billing.enabled is
 * off it returns the NullDriver, so a clone without billing has a clean no-op facade. Drivers are
 * registered lazily via extend() (the driver service providers register their own).
 */
final class BillingManager
{
    /** @var array<string, callable(): BillingDriver> */
    private array $factories = [];

    /** @var array<string, callable(PaymentAccount): BillingDriver> */
    private array $accountFactories = [];

    /** @var array<string, callable(PaymentAccount): AccountWebhooks> */
    private array $accountWebhookFactories = [];

    /** @var array<string, callable(PaymentAccount): PaymentCsp> */
    private array $accountCspFactories = [];

    /** @var array<string, callable(PaymentAccount): PaymentCsp> */
    private array $accountRedirectCspFactories = [];

    /** @var array<string, callable(PaymentAccount): EnabledPaymentMethods> */
    private array $accountPaymentMethodFactories = [];

    public function __construct(private readonly Repository $config) {}

    /** Register a driver factory under a name (e.g. "stripe"). */
    public function extend(string $name, callable $factory): void
    {
        $this->factories[$name] = $factory;
    }

    /**
     * Register how a driver builds itself for one payment account (the driver service providers do).
     *
     * @param  callable(PaymentAccount): BillingDriver  $factory
     */
    public function extendAccounts(string $name, callable $factory): void
    {
        $this->accountFactories[$name] = $factory;
    }

    /**
     * Register how a driver verifies and reads the webhooks of one payment account.
     *
     * @param  callable(PaymentAccount): AccountWebhooks  $factory
     */
    public function extendAccountWebhooks(string $name, callable $factory): void
    {
        $this->accountWebhookFactories[$name] = $factory;
    }

    /**
     * Register the Content-Security-Policy sources a page needs for the front end of one payment account's provider.
     *
     * @param  callable(PaymentAccount): PaymentCsp  $factory
     */
    public function extendAccountCsp(string $name, callable $factory): void
    {
        $this->accountCspFactories[$name] = $factory;
    }

    /**
     * Register the Content-Security-Policy source a page needs that redirects one payment account's customer to the
     * provider's hosted pages: the origins of those pages, for `form-action`.
     *
     * @param  callable(PaymentAccount): PaymentCsp  $factory
     */
    public function extendAccountRedirectCsp(string $name, callable $factory): void
    {
        $this->accountRedirectCspFactories[$name] = $factory;
    }

    /**
     * Register how a driver lists the payment methods one payment account has turned on.
     *
     * @param  callable(PaymentAccount): EnabledPaymentMethods  $factory
     */
    public function extendAccountPaymentMethods(string $name, callable $factory): void
    {
        $this->accountPaymentMethodFactories[$name] = $factory;
    }

    /**
     * Every driver name that has been registered, sorted.
     *
     * Read-only, and it exists so a guard can ask "what drivers does this install actually have" without
     * a hand-written list. The recurring defect in this package is a seam that only one driver binds; a
     * test that enumerated the drivers itself could not catch the next one, because the list would be
     * updated by the same person who forgot the binding.
     *
     * @return list<string>
     */
    public function names(): array
    {
        $names = array_keys($this->factories);
        sort($names);

        return $names;
    }

    /**
     * Whether the package runs in payments-only mode: payment accounts take payments, and the SaaS surface (the
     * account hub, the scheduled runs and the default effects) stays off.
     */
    public function paymentsOnly(): bool
    {
        return $this->config->get('billing.mode') === 'payments';
    }

    /** Whether billing is enabled (the master switch). */
    public function enabled(): bool
    {
        return (bool) $this->config->get('billing.enabled', true);
    }

    /**
     * The active driver — the NullDriver when billing is disabled, otherwise the named/default driver.
     *
     * ## It takes a name, and deliberately not an owner
     *
     * There is no per-owner driver resolution in this package, and that is a decision rather than a gap.
     * One install runs one provider; changing providers is a migration of `billing.default`, not a mixed
     * mode. Two things follow from it, and both are load-bearing:
     *
     * A marketplace does not need per-owner drivers. Its sellers are merchants at the same provider,
     * scoped by {@see MerchantScope} — a creator's subscription is
     * charged through the platform's driver against the creator's own connected account, not through a
     * different provider.
     *
     * And the seams that a screen resolves — `WebhookVerifier`, `WebhookEventMapper`, `PaymentMethods`,
     * `PaymentCsp`, `Invoices`, `SubscriptionActions`, `ProrationStrategy` — are bound once at boot, by
     * the active driver's service provider. A screen resolving one of those gets the active driver's
     * implementation whatever row it is rendering. Making that owner-dependent would mean routing every
     * resolution through this manager, and the contracts would stop being container bindings — which is
     * exactly what a consumer overrides today to swap one behavior without forking a driver.
     *
     * So a caller passing a name that is not the default gets that driver's rails, engine and
     * capabilities — the three things this manager owns — and the boot-bound seams stay as they are.
     * Anything else would be half a mechanism, and half a mechanism here means a subscriber silently
     * charged through the wrong provider.
     *
     * An application that takes payments for many shops, each with its own account at Mollie or Stripe, does
     * not resolve those through this method either. It hands the account to {@see self::forAccount()}, which
     * builds a driver whose client carries that account's credential, and never a driver for anyone else.
     */
    public function driver(?string $name = null): BillingDriver
    {
        if (! $this->enabled()) {
            return new NullDriver;
        }

        $name ??= $this->defaultDriver();

        $factory = $this->factories[$name] ?? throw new UnsupportedDriver($name);

        return $factory();
    }

    /**
     * The driver for one payment account the application supplies: rails whose client carries the account's
     * credential and test mode, and the capabilities of its provider.
     *
     * The account is passed, never looked up from something global, so a request or a job charges the shop it
     * names and no other, also in a worker that serves many shops one after another. Its engine is the null
     * engine: an account charges what it is handed, and the subscriptions of the install keep running on
     * {@see self::driver()}. The seams bound at boot stay those of the install's driver.
     *
     * With billing switched off this is the NullDriver, as {@see self::driver()} is.
     *
     * @throws UnsupportedDriver when no driver builds itself for the account's provider
     */
    public function forAccount(PaymentAccount $account): BillingDriver
    {
        if (! $this->enabled()) {
            return new NullDriver;
        }

        $factory = $this->accountFactories[$account->provider] ?? throw new UnsupportedDriver($account->provider, 'extendAccounts');

        return $factory($account);
    }

    /**
     * The verifier and the mapper for the webhooks of one payment account.
     *
     * @throws UnsupportedDriver when no driver reads webhooks for the account's provider
     */
    public function accountWebhooks(PaymentAccount $account): AccountWebhooks
    {
        $factory = $this->accountWebhookFactories[$account->provider] ?? throw new UnsupportedDriver($account->provider, 'extendAccountWebhooks');

        return $factory($account);
    }

    /**
     * The Content-Security-Policy sources a page needs for the front end of one payment account's provider: its
     * script and frames where the page mounts the provider's element, and its checkout where the page redirects.
     *
     * The account hub keeps scoping the sources of the install's own driver to its routes, through the
     * {@see PaymentCsp} bound at boot. A shop page that takes payments into an account adds that account's sources
     * to its own policy, on the pages that take payments and nowhere else.
     *
     * @throws UnsupportedDriver when no driver declares sources for the account's provider
     */
    public function accountCsp(PaymentAccount $account): PaymentCsp
    {
        $factory = $this->accountCspFactories[$account->provider] ?? throw new UnsupportedDriver($account->provider, 'extendAccountCsp');

        return $factory($account);
    }

    /**
     * The CSP sources a page needs for the front ends of several payment accounts, each source once: a shop page that
     * takes payments into a Mollie and a Stripe account allows the sources of both.
     */
    public function accountsCsp(PaymentAccount ...$accounts): PaymentCsp
    {
        return new MergedPaymentCsp(...array_map($this->accountCsp(...), array_values($accounts)));
    }

    /**
     * The CSP sources a page needs that only sends the customers of payment accounts to their providers' hosted pages,
     * such as a checkout that submits a form and follows the checkout link it gets back: `form-action` with the origins
     * of those pages, each once, and no script, frame or connection of a provider. A provider whose driver serves no
     * hosted page names none.
     */
    public function accountsRedirectCsp(PaymentAccount ...$accounts): PaymentCsp
    {
        return new MergedPaymentCsp(...array_map(
            fn (PaymentAccount $account): PaymentCsp => isset($this->accountRedirectCspFactories[$account->provider])
                ? ($this->accountRedirectCspFactories[$account->provider])($account)
                : new RedirectPaymentCsp([]),
            array_values($accounts),
        ));
    }

    /**
     * The payment methods one payment account has turned on at its provider, read with the account's own credential,
     * for a settings screen that shows them and a checkout that offers them.
     *
     * @throws UnsupportedDriver when no driver lists the payment methods of the account's provider
     */
    public function accountPaymentMethods(PaymentAccount $account): EnabledPaymentMethods
    {
        $factory = $this->accountPaymentMethodFactories[$account->provider] ?? throw new UnsupportedDriver($account->provider, 'extendAccountPaymentMethods');

        return $factory($account);
    }

    public function capabilities(?string $name = null): DriverCapabilities
    {
        return $this->driver($name)->capabilities();
    }

    /** Whether the marketplace path is switched on (the single, authoritative key). */
    public function marketplaceEnabled(): bool
    {
        return (bool) $this->config->get('billing.marketplace.enabled', false);
    }

    /**
     * The driver's marketplace rails, or a loud failure.
     *
     * There is no null-returning variant on purpose. A caller that has reached this point intends to
     * route money; handing it null would move the decision to whatever dereferences the result, which
     * is a fatal error one layer further from the cause. The two refusals are told apart because their
     * fixes are different: billing off is a master-switch problem, a non-routing driver is a driver
     * choice.
     */
    public function marketplaceRails(?string $name = null): MarketplaceRails
    {
        if (! $this->enabled()) {
            throw MarketplaceUnsupported::billingDisabled();
        }

        $driver = $this->driver($name);

        if (! $driver instanceof RoutesMoney) {
            throw MarketplaceUnsupported::driverCannotRoute($driver->name());
        }

        return $driver->marketplaceRails();
    }

    private function defaultDriver(): string
    {
        $default = $this->config->get('billing.default', 'stripe');

        return is_string($default) ? $default : 'stripe';
    }
}
