# Configuration

`billing:install` already published the config during [installation](installation.md); run this only to
re-publish it, or if you installed with `--no-config`:

```bash
php artisan vendor:publish --tag=billing-config
```

It writes three files, each documented inline:

- **`config/billing.php`** — the master switch, the active driver, the tier catalog (the upgrade ranking),
  dunning ladder, tax, your company details (for e-invoices) and DATEV numbers.
- **`config/account.php`** — the account-hub route prefix, middleware, view set, and scoped CSP.
- **`config/license.php`** — what each tier _unlocks_ (boolean feature grants + numeric limits), kept
  separate from pricing.

> **Billing and licensing are two orthogonal domains.** `billing.php` is what your customers **pay**;
> `license.php` is what a tier **unlocks**. They are deliberately separate, and **neither ever blocks a
> public or marketing surface** — both fail open. Billing code never reads `license.*` config; the single
> bridge is the `License` contract, so a tier's entitlements can be license-backed without coupling pricing
> to licensing. An arch guard (`LicenseBillingSeparationTest`) pins the boundary so it cannot regress.

Point the package at your billable model:

```php
// config/billing.php
'customer' => ['model' => App\Models\User::class, 'column' => 'stripe_id'],
```

**Set `billing.customer.model` to your billable model** — without it, no subscription webhook can find its
owner.

For the complete key-by-key reference, see [Configuration reference](../reference/configuration.md). To
define your tiers and pricing, continue to [Tiers and pricing](tiers-and-pricing.md).

---

[← Back to the documentation index](../README.md)
