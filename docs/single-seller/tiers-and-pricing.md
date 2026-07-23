# Tiers and pricing

Define your tiers in `config/billing.php`:

```php
// config/billing.php
'tiers' => [
    'free' => ['label' => 'Free'],
    'pro'  => [
        'label'          => 'Pro',
        'provider_price' => env('BILLING_PRICE_PRO'), // the active provider's price reference
        'price_display'  => ['amount' => 1900, 'currency' => 'EUR'],
        'interval'       => 'month',
    ],
],
```

A tier may carry `'byok' => true` — a "bring your own keys" tier, where the customer runs on their own
provider credentials rather than being metered and billed the usual way. The package exposes it through
`Entitlements::isByok()` / `TierCatalog::isByok($key)`; your app reads that flag to route a BYOK owner around
its own metering/server-key path. The package itself holds no server-side execution key, so it never puts a
BYOK owner on a metered server path — that boundary lives in your app, and `isByok()` is the seam it reads.
BYOK is orthogonal to payment method: a BYOK tier can still be a paid subscription.

Each paid tier's `provider_price` is the **active provider's price reference** — provider-neutral, not
hardcoded to one provider. It may be declared two ways:

- a **scalar string** — one price for whichever driver is active (the single-provider common case). On Stripe
  that string is a Stripe price id (`price_...`); create the product and its recurring price in the Stripe
  dashboard or via the API, then put the id in `.env`.
- a **per-provider map** — `['stripe' => 'price_...']`, so one tier config carries the
  right id for each driver; the active driver is `billing.default` unless a provider is named.

```dotenv
BILLING_PRICE_PRO=price_...
```

A tier whose `provider_price` is empty (or a map with no entry for the active provider) cannot be checked out
— it resolves to `null` and the tier is treated as not purchasable on that provider, never charged at a wrong
price. That resolution is the **anti-price-injection allowlist**: the client submits a tier _key_, and the
`ProviderPriceResolver` maps it to a price only config declares, so a price id can never be smuggled in from
the request. Read a resolved id with `ProviderPriceResolver::forTier($key)` / `forAddon($key)`.

## One pricing source

For a **pricing surface** — the in-app upgrade grid and a public `/pricing` page — both render from one
config-authoritative source, `PricingCatalog::cards()`, so they can never promise different things. A tier's
feature bullets live in config as an ordered list of **translation keys** (your app owns the strings, in
every locale); an optional `highlight`/`badge` emphasizes a card:

```php
'pro' => [
    'label' => 'Pro', /* … price as above … */
    'features'  => ['pricing.pro.projects', 'pricing.pro.priority_support'], // i18n KEYS, never raw text
    'highlight' => true,
    'badge'     => 'pricing.badge.popular',
],
```

`PricingCatalog::bulletsFor($tierKey)` resolves those keys to the current locale, in order. Because the
bullets come only from config, the grid and `/pricing` cannot drift.

## Resolving a billable to its tier

`TierResolver` maps a billable to its tier. The package binds **`ColumnTierResolver`** by default — it reads
the denormalized `plan` column (`billing.tier_column`). If your app does not keep a tier column, rebind it to
`SubscriptionTierResolver` (which maps the active price back to a tier) in one line:

```php
// A service provider
$this->app->bind(
    Pushery\Billing\Contracts\TierResolver::class,
    Pushery\Billing\Resolvers\SubscriptionTierResolver::class,
);
```

Grandfathering is handled through per-tier `legacy_prices`; a multi-item subscription is handled too (the
tier item is resolved by price, foreign items untouched).

## The catalog services

The tier, add-on and pricing surfaces are read through **config-authoritative catalog services** — resolve
them from the container and use them out of the box; the config is the single source they read, so nothing
drifts.

- **`PricingCatalog`** — the shared pricing surface behind both the in-app upgrade grid and a public
  `/pricing` page. `tiers()` / `purchasable()` list the catalog; `cards()` and `upgradeCards($currentKey)`
  build the render-ready cards; `bulletsFor($key)` resolves a tier's feature bullets to the current locale.
  Because both surfaces read the one source, they can never promise different things.
- **`TierCatalog`** (contract; `ConfigTierCatalog` by default) — `all()`, `find($key)`, `label($key)`,
  `priceDisplay($key)`, and the flags `isByok($key)` / `isUntouchable($key)`.
- **`ConfigAddonCatalog`** — `all()`, `exists($key)`, `label($key)`, `priceFor($key)`,
  `providerPriceFor($key)`, and `grantsFor($key)` for a prepaid-unit add-on (see
  [Usage-based billing](usage-based-billing.md)). Unlike the tier and plan catalogs it is a concrete class,
  not a contract, so extend it by binding your own subclass rather than rebinding an interface.

Use them as-is for the common case; **extend by rebinding the contract** when you need catalog data from
somewhere other than config (a database of tiers, say). Rebind `TierCatalog` (or `PlanCatalog`) to your own
implementation the same way `TierResolver` is rebound above — every package surface then reads your catalog,
and the pricing grid and `/pricing` stay in lockstep because they still go through `PricingCatalog`.

### Feature bullets, without drift

A tier's feature bullets are the `features` block of i18n **keys** shown above — never raw strings. The keys
resolve through the translation namespace, so the same bullet renders in every locale, and the grid and
`/pricing` show the same list because both call `PricingCatalog::bulletsFor()`. To change a bullet you edit
the config key order or the translation, never two copies of the text. See
[i18n and translations](i18n-and-translations.md) for overriding those strings and the register they must
keep.

---

[← Back to the documentation index](../README.md)
