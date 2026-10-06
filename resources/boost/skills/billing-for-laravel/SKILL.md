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
`pushery/billing-for-laravel` package. The full reference is at
<https://docs.pushery.com/billing-for-laravel/>. Reach for the package's public API,
and never re-implement what it already ships.

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
php artisan vendor:publish --tag=billing-migrations
php artisan vendor:publish --tag=billing-views
php artisan vendor:publish --tag=billing-lang
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

## Commands

The service provider schedules the recurring commands, so run one of those by hand only to
catch up. Nothing is scheduled while `billing.enabled` is off. Options and exit codes are in
the command reference at <https://docs.pushery.com/billing-for-laravel/reference/commands>.

| Command | What it does |
| --- | --- |
| `billing:usage:flush` | Every minute: hands back expired usage holds and reports recorded usage to the provider that bills it |
| `billing:run` | Hourly: advances the recurring cycle (a no-op under Stripe, which drives its own) |
| `billing:dunning:advance` | Daily: walks the dunning ladder, with escalating warnings and late fees |
| `billing:dunning:remind` | Daily: reminds marketplace subscriptions in arrears that are still inside the cure window |
| `billing:dunning:expire` | Daily: expires marketplace subscriptions whose cure window ran out, with one final notice |
| `billing:cards:warn` | Daily: warns owners whose card is about to expire |
| `billing:trials:warn` | Daily: warns owners whose generic trial is about to end |
| `billing:usage:reconcile` | Daily: reads the provider's usage totals back and alarms on drift; `--redrive` retries the rollups a flush gave up on |
| `billing:prune` | Daily: ages out stored webhook payloads and expired financial records |
| `billing:sync` | Reconciles subscriptions from the provider onto the local rows |
| `billing:webhooks:replay` | Re-drives webhook effects that failed (`--failed`), or runs an effect that can repeat again (`--rerun`) |
| `billing:doctor` | Checks the installation: host key type, missing tables, Stripe webhook endpoints, and the age of rate tables and series |
| `billing:meters:check` | Verifies that every configured usage meter exists and is active at the provider |
| `billing:erase {owner}` | Erases an owner's billing data |
| `billing:export {owner}` | Exports everything the package holds about one owner, as JSON |
| `billing:tier:grant {owner} {tier}` | Comps an owner onto a tier out of band, recorded on the audit trail |
| `billing:subscription:cancel {owner}` | Ends a subscription immediately, recorded on the audit trail |
| `billing:release-claim {order}` | Hands a stranded cycle back to the biller after checking the provider |
| `billing:vouchers:volume` | Announces voucher volume that has reached a supervisory threshold (scheduled where vouchers are on) |
| `billing:datev:export` | Exports a period of invoices as a DATEV EXTF booking batch |
| `billing:tax-return:export` | Exports a quarter's sales as tax-return lines, corrections included |
| `billing:recapitulative-statement:export` | Exports reverse-charged sales to businesses in other member states, per VAT ID |
| `billing:reporting:run {year}` | Produces a reporting period's official record, refusing while it is implausible |
| `billing:reporting:file {year}` | Records that a produced record was actually submitted |
| `billing:filings:announce` | Daily: warns about filing obligations falling due soon |
| `billing:markets:record` | Writes down which markets changed standing, and by whom |
| `billing:rates:probe` | Asks the source whether the shipped VAT rates still match (off unless enabled) |
| `billing:tax-rates:check` | Asks the source what the rates should be and writes a proposal (off unless enabled) |
| `billing:tax-holds:remind` | Daily: reminds the creators whose tax attestation is due for renewal |
| `billing:tax-holds:announce` | Daily: tells the merchants whose tax attestation just expired |
| `billing:tax-holds:warn` | Daily: tells the merchants who never declared that the deadline is coming |
| `billing:tax-status:reconcile` | Daily: flips creators whose turnover has broken a small-business limit |
| `billing:settlements:restate` | Daily: issues again the settlements a corrected creator standing made wrong |
| `billing:exchange-rates:import` | Daily: fetches published exchange rates into the local store |
| `billing:exchange-rates:import-file` | Imports announced monthly averages from a file |
| `billing:exchange-rates:freeze-reporting` | Freezes the reporting rate onto a closed period's documents |
| `billing:exchange-rates:freeze-statement` | Freezes the recapitulative statement's rate onto a closed period's reverse-charged sales |
| `billing:marketplace:preflight` | Lists what still has to hold before a multi-merchant go-live |
| `billing:merchant:onboard {type} {id}` | Creates a merchant's connected account and prints the onboarding link |
| `billing:merchant:status` | Shows what each merchant account may do, and what the provider is still waiting for |
| `billing:merchant:refresh` | Asks the provider for merchant capabilities after a missed webhook |
| `billing:merchant:reopen {type} {id}` | Begins again with a merchant whose relationship had ended |
| `billing:merchants:reconcile` | Compares the merchant journal against what the provider says it moved |
| `billing:marketplace:retry-transfers` | Moves the merchant shares that failed to move after their sale was paid |
| `billing:protection:advance` | Releases or escalates buyer-protection holds whose deadlines have passed |
| `billing:seller-data:escalate` | Daily, marketplace only: reminds sellers whose record is incomplete, and applies or ends measures |

## Boundaries

- If the package is missing a capability, file it upstream rather than forking or
  re-implementing it locally — that is how the capability comes back for every
  consumer.
- Do not add billing tables, invoice-numbering, or tax logic in the app when the
  package already owns them; extend through the seams above.
