# Billing for Laravel — documentation

Provider-neutral billing for Laravel: subscriptions, invoices, metered usage, dunning, tax and
e-invoicing. Stripe-first, on provider-neutral contracts.

This tree is the package's full documentation. The [README](../README.md) is the showcase; everything
below is the detail it used to carry.

## Start here

- **[Choosing your setup](choosing-your-setup.md)** — the six decisions to make before `billing:install`,
  each with its config key and its default.

## The guide

The whole surface for an app that sells its own product. Start at installation and read in order, or jump to
what you need.

- [Installation](single-seller/installation.md) — `composer require`, `billing:install`, the owner-columns migration
- [Configuration](single-seller/configuration.md) — the three config files and why billing and licensing are orthogonal
- [Tiers and pricing](single-seller/tiers-and-pricing.md) — the tier catalog, the catalog services, `provider_price`, the anti-price-injection allowlist, grandfathering
- [i18n and translations](single-seller/i18n-and-translations.md) — publishing and overriding the translation namespace, adding a locale, the informal register, the parity gate
- [Subscriptions](single-seller/subscriptions.md) — hosted checkout, in-app swap with proration preview, cancel-into-grace, resume, trials
- [The account hub](single-seller/account-hub.md) — the publishable Livewire screens, config-driven routes, the banner, hosting your own screens
- [Usage-based billing](single-seller/usage-based-billing.md) — meters, included allowance, prepaid units, quota policies, oversell-safe metering
- [Dunning and suspension](single-seller/dunning-and-suspension.md) — the dunning ladder, late fees, the 423 and 402 gates, the recovery screen
- [Invoices and e-invoicing](single-seller/invoices-and-e-invoicing.md) — the immutable `InvoiceRecord`, XRechnung (UBL), ZUGFeRD (CII), the hybrid PDF/A-3, corrections
- [Taxes](single-seller/taxes.md) — `provider` vs `eu_oss` vs `none`, the `TaxCalculator` seam, reverse charge on a validated VAT id
- [Accounting and DATEV](single-seller/accounting-and-datev.md) — the EXTF booking batch, account numbers, the booking-field convention
- [Webhooks](single-seller/webhooks.md) — the endpoint, signature verification, idempotency, the shipped effects, replay
- [Admin and support](single-seller/admin-and-support.md) — `BillingAdmin`, the optional Livewire console, the app-defined admin gate, the audit trail, metrics
- [Data protection](single-seller/data-protection.md) — `billing:export`, `billing:erase`, `billing:prune`, why invoices stay, the retention windows

## Reference

- [Configuration reference](reference/configuration.md) — every key in the three config files, its env variable and its default
- [Command reference](reference/commands.md) — every Artisan command, its cadence and exit behavior
- [Event reference](reference/events.md) — the provider-neutral domain events and their payloads
- [Contract reference](reference/contracts.md) — the extension seams and their guarantees
- [Database reference](reference/database.md) — the package tables, their columns and what erasure does to each

## Guides

- [Upgrading](guides/upgrading.md) — what each released version needs from you
- [Migrating from your own billing code](guides/migrating-from-custom-billing.md) — adopt the package in place, one seam at a time
- [Migrating from Cashier](guides/migrating-from-cashier.md) — adopt existing Cashier subscriptions with `billing:sync`
- [Testing](guides/testing.md) — `Billing::fake()`, asserting on domain events, cross-engine testing
- [Troubleshooting](guides/troubleshooting.md) — what each fail-closed guard's error means

## Compliance

- [Invariants](compliance/invariants.md) — the invariants the package enforces fail-closed
- [Retention and erasure](compliance/retention-and-erasure.md) — the retention and deletion matrix
- [Security](compliance/security.md) — CSP, the PCI SAQ-A boundary, the webhook-secret guard, the admin gate, and the payment-services/e-money license line
