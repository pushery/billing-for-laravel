---
name: billing-for-laravel
description: >
  Install, configure, and apply the Billing for Laravel package in a Laravel
  application — provider-neutral subscriptions, invoices, metered usage, dunning,
  tax and e-invoicing, Stripe-first.
license: MIT
metadata:
  author: pushery
---

# Billing for Laravel

Use this skill when a Laravel application installs or integrates the
`pushery/billing-for-laravel` package. Laravel Boost surfaces it inside consuming
applications, so keep it focused on adoption — reach for the package's public API,
never re-implement what it already ships.

## Primary Goal

Apply the package's public API in the smallest correct way for the consuming
application. The billing core (models, webhooks, invoicing, tax, contracts) needs
no UI; the account-hub screens are optional and only render when Livewire is
installed.

## Workflow

### 1. Install

```bash
composer require pushery/billing-for-laravel
php artisan billing:install
```

`billing:install` publishes the config and registers the server-side tables (they
also load automatically). Point the package at your billable model with
`billing.customer.model`, or no webhook can find its subscription's owner.

### 2. Configure

Publish one asset kind, or everything at once:

```bash
php artisan vendor:publish --tag=billing-config
php artisan vendor:publish --tag=billing        # config + migrations + views + lang
```

Key settings in `config/billing.php`:

- `billing.enabled` — the master switch. `false` makes the whole surface disappear
  (a clean no-op façade), so a consumer can ship the package inert.
- `billing.owner` (`user` | `team`) — whether billing is owned by the acting user or
  its team.
- `billing.default` — the driver (`stripe`), plus its credentials block.
- `billing.tax` — `none` | `stripe` | `eu_oss`; the EU-OSS calculator is
  seller-country aware and only zero-rates a validated cross-border intra-EU B2B
  supply (never under-charges VAT).

### 3. Apply the public API

- Read state through the `Billing` facade and the published contracts — never query
  the package's tables directly.
- Business logic hangs off the package's domain events
  (`PaymentSucceeded`, `SubscriptionStateChanged`, `InvoiceFinalized`, …): listen,
  do not poll.
- Swap behavior by binding a contract (`TaxCalculator`, `UsageProvider`,
  `DiscountResolver`, `VatIdValidator`, …) to your own implementation; the defaults
  are safe, offline-capable no-ops.
- The webhook endpoint authenticates by signature; register your provider's webhook
  to `billing.webhook_path`.

## Boundaries

- If the package is missing a capability, file it upstream rather than forking or
  re-implementing it locally — that is how the capability comes back for every
  consumer.
- Do not add billing tables, invoice-numbering, or tax logic in the app when the
  package already owns them; extend through the seams above.
