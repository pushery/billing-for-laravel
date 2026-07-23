# Installation

```bash
composer require pushery/billing-for-laravel
```

The service provider is registered automatically through package discovery. The Stripe driver builds on
[Cashier](https://laravel.com/docs/billing), so set your Stripe keys (`STRIPE_KEY`, `STRIPE_SECRET`,
`STRIPE_WEBHOOK_SECRET`) as usual.

Then run the installer:

```bash
php artisan billing:install
php artisan migrate
```

> **Billable model isn't `App\Models\User`?** `billing:install` reads the target table from
> `billing.customer.model` when it generates the migration, so set `BILLING_CUSTOMER_MODEL` in your `.env`
> (or pass `--table=your_table`) **before** you run it. Otherwise it adds the billing columns to `users`.

`billing:install` publishes the config and generates a migration that adds the **tier column** (`plan`) and
the Cashier customer columns (`stripe_id`, `pm_type`, `pm_last_four`, `trial_ends_at`) to your owner model's
table — the columns that live on _your_ table, which no package migration can create without knowing which
table it is. It targets the table of `billing.customer.model` (or `users`); override with `--table`.

Renamed `billing.tier_column` or `billing.customer.column`? The generated migration follows your config, and
every package surface reads the column you configured. The one boundary is Cashier's own API
(`Cashier::findBillable()`, the `Billable` trait), which is hardcoded to `stripe_id` — so rename freely
unless you also drive Cashier directly.

The package's own server-side billing tables load automatically; publish them only if you would rather manage
them in your app:

```bash
php artisan vendor:publish --tag=billing-migrations
```

Next: [Configuration](configuration.md).

---

[← Back to the documentation index](../README.md)
