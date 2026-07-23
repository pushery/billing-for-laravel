# Billing for Laravel — documentation

Provider-neutral billing for Laravel: subscriptions, invoices, metered usage, dunning, tax and
e-invoicing. Stripe-first, with Mollie and Adyen planned on the same neutral contracts.

This tree is the package's full documentation. The [README](../README.md) is the showcase; everything
below is the detail it used to carry.

## Start here

- **[Choosing your mode](choosing-your-mode.md)** — a three-question path into exactly one of the four
  operating modes. Most apps are **Mode S (single seller)** and can go straight to the single-seller guide.

## Mode S — single seller (the default, the 90% case)

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

## Marketplace and other jurisdictions

- **Mode K (commission marketplace)** — [overview](marketplace/overview.md) · [commission model](marketplace/commission-model.md) · [self-billing](marketplace/self-billing.md) · [seller status](marketplace/seller-status.md) · [payouts and clawbacks](marketplace/payouts-and-clawbacks.md) · [reporting](marketplace/reporting.md)
- **Mode V (intermediary marketplace)** — [overview](marketplace-intermediary/overview.md) · [commission invoice](marketplace-intermediary/commission-invoice.md) · [consumer-to-consumer](marketplace-intermediary/consumer-to-consumer.md) · [buyer fee](marketplace-intermediary/buyer-fee.md)
- **Mode X (another jurisdiction)** — [profiles](jurisdictions/profiles.md) · [the German profile](jurisdictions/profile-de.md) · [writing a profile](jurisdictions/writing-a-profile.md)

The marketplace and jurisdiction pages are scaffolds today: their goal and outline are set, and the feature
work that owns each fills it in. Mode S stays byte-identical whether or not the marketplace is enabled.

## Reference

- [Configuration reference](reference/configuration.md) — the complete key reference (generated)
- [Command reference](reference/commands.md) — every Artisan command, its cadence and exit behavior
- [Event reference](reference/events.md) — the provider-neutral domain events and their payloads
- [Contract reference](reference/contracts.md) — the extension seams and their guarantees
- [Database reference](reference/database.md) — the package tables and provider-neutral column naming

## Guides

- [Upgrading](guides/upgrading.md) — upgrade paths per version
- [Migrating from your own billing code](guides/migrating-from-custom-billing.md) — adopt the package in place, one seam at a time
- [Migrating from Cashier](guides/migrating-from-cashier.md) — adopt existing Cashier subscriptions with `billing:sync`
- [Testing](guides/testing.md) — `Billing::fake()`, asserting on domain events, cross-engine testing
- [Troubleshooting](guides/troubleshooting.md) — what each fail-closed guard's error means

## Compliance

- [Invariants](compliance/invariants.md) — the invariants the package enforces fail-closed
- [Retention and erasure](compliance/retention-and-erasure.md) — the retention and deletion matrix
- [Security](compliance/security.md) — CSP, the PCI SAQ-A boundary, the webhook-secret guard, the admin gate, and the payment-services/e-money license line
