<p align="center">
  <a href="https://github.com/pushery/billing-for-laravel">
    <img src="art/header.png" alt="Billing for Laravel" width="100%">
  </a>
</p>

# Billing for Laravel

[![Latest Version](https://img.shields.io/packagist/v/pushery/billing-for-laravel.svg)](https://packagist.org/packages/pushery/billing-for-laravel)
[![PHP Version](https://img.shields.io/packagist/dependency-v/pushery/billing-for-laravel/php.svg)](https://packagist.org/packages/pushery/billing-for-laravel)
[![PHPStan](https://img.shields.io/badge/PHPStan-max-blue.svg)](https://phpstan.org)
[![Code Style](https://img.shields.io/badge/code%20style-pint-orange.svg)](https://laravel.com/docs/pint)
[![License](https://img.shields.io/packagist/l/pushery/billing-for-laravel.svg)](LICENSE)

Provider-neutral billing for Laravel: subscriptions, invoices, metered usage, dunning, tax and e-invoicing. Stripe-first, with Mollie and Adyen planned on the same neutral contracts.

Everything crosses a small set of contracts, so your app talks to _billing_ — not to Stripe. The Stripe driver ships today. The contracts are the seam a second provider slots into — the Mollie and Adyen drivers are not built yet.

## Highlights

- **Two-layer, provider-neutral core** — `PaymentRails` (moves money, stores mandates) and `BillingEngine` (the recurring cycle). Money crosses the boundary as a `Money` value object, never a raw provider response.
- **Subscriptions** — in-app upgrade/downgrade **swap** with a proration **preview**, cancel-into-grace, resume, and immediate cancel — the client submits a tier _key_, never a price (anti-price-injection).
- **Account hub** — a publishable Livewire hub (overview, subscription, change-plan, payment methods, invoices, usage, payment recovery, danger zone) with config-driven routes and a self-contained Blade view set. Drop a `<x-billing::banner />` into your layout to surface a failed payment, a lapsing grace period or a trial about to end.
- **Webhooks** — an idempotent, signature-verified backbone with effects out of the box (plan-sync, add-on credit, dunning notice, invoice persistence) and a fail-loud webhook-secret guard.
- **Usage-based billing** — charge a base fee _plus_ what a customer actually used ("19 € a month, plus 0.50 € per 1 000 emails, first 10 000 included"). Record usage with one call; the package counts it against the tier's included allowance, shows it on the usage screen, and reports it to the provider that bills it — with a retry that cannot double-bill and an outage that cannot silently lose revenue. A `billing.quota:<meter>` middleware (and a `UsageGate`) applies the meter's policy: a hard-stop / refuse meter is blocked past its allowance, a degrade meter still serves but is flagged, a fair-use meter never blocks. For a limit that must not be oversold, meter the work through `UsageRecorder::meter()` — it HOLDS the allowance under a row lock before the work runs, records only what the work actually consumed, and hands the rest back. The middleware is the cheap pre-check in front of it: on its own it is a point-in-time read, and two simultaneous requests can both pass a boundary check.
- **Entitlements & seats** — a separate `License` gate for what a tier _unlocks_ (feature grants + numeric limits), independent of what it costs, plus team seats and a User-XOR-Team owner model.
- **Dunning, suspension & tax** — a config-driven multi-level dunning ladder, a per-surface `423 Locked` suspension gate driven by a stored delinquency clock (outage-safe), and provider tax (Stripe Tax) on the invoice with a pluggable `TaxCalculator` seam for a local computation path.
- **E-invoicing** — a dependency-free **EN 16931 / XRechnung** (UBL) writer and a **DATEV** (EXTF Buchungsstapel) export.
- **Security-first** — a `billing.enabled` master switch that makes the whole surface disappear, a scoped Content-Security-Policy for the hub, a fail-closed money-eligibility gate, and card capture that happens on the provider's own hosted page, so no card data ever touches your app (PCI SAQ-A).

## Requirements

- PHP 8.4+
- Laravel 13+

Tested against SQLite, PostgreSQL and MySQL 8.4, so it runs on Laravel Cloud (serverless Postgres and MySQL 8.4 LTS) out of the box.

## Installation

```bash
composer require pushery/billing-for-laravel
```

The service provider is registered automatically through package discovery. The Stripe driver builds on [Cashier](https://laravel.com/docs/billing), so set your Stripe keys (`STRIPE_KEY`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`) as usual.

Then run the installer:

```bash
php artisan billing:install
php artisan migrate
```

> **Billable model isn't `App\Models\User`?** `billing:install` reads the target table from `billing.customer.model` when it generates the migration, so set `BILLING_CUSTOMER_MODEL` in your `.env` (or pass `--table=your_table`) **before** you run it. Otherwise it adds the billing columns to `users`.

`billing:install` publishes the config and generates a migration that adds the **tier column** (`plan`) and the Cashier customer columns (`stripe_id`, `pm_type`, `pm_last_four`, `trial_ends_at`) to your owner model's table — the columns that live on _your_ table, which no package migration can create without knowing which table it is. It targets the table of `billing.customer.model` (or `users`); override with `--table`.

Renamed `billing.tier_column` or `billing.customer.column`? The generated migration follows your config, and every package surface reads the column you configured. The one boundary is Cashier's own API (`Cashier::findBillable()`, the `Billable` trait), which is hardcoded to `stripe_id` — so rename freely unless you also drive Cashier directly.

The package's own server-side billing tables load automatically; publish them only if you would rather manage them in your app:

```bash
php artisan vendor:publish --tag=billing-migrations
```

## Configuration

`billing:install` already published the config in the step above; run this only to re-publish it, or if you installed with `--no-config`:

```bash
php artisan vendor:publish --tag=billing-config
```

It writes three files, each documented inline:

- **`config/billing.php`** — the master switch, the active driver, the tier catalog (the upgrade ranking), dunning ladder, tax, your company details (for e-invoices) and DATEV numbers.
- **`config/account.php`** — the account-hub route prefix, middleware, view set, and scoped CSP.
- **`config/license.php`** — what each tier _unlocks_ (boolean feature grants + numeric limits), kept separate from pricing.

Point the package at your billable model and define your tiers:

```php
// config/billing.php
'customer' => ['model' => App\Models\User::class, 'column' => 'stripe_id'],

'tiers' => [
    'free' => ['label' => 'Free'],
    'pro'  => [
        'label'          => 'Pro',
        'provider_price' => env('BILLING_PRICE_PRO'), // the Stripe price id
        'price_display'  => ['amount' => 1900, 'currency' => 'EUR'],
        'interval'       => 'month',
    ],
],
```

Each paid tier's `provider_price` is a **Stripe price id**. Create the product and its recurring price in the Stripe dashboard (or via the API), then put the id in `.env` — a tier whose `provider_price` is empty cannot be checked out:

```dotenv
BILLING_PRICE_PRO=price_...
```

For a **pricing surface** — the in-app upgrade grid and a public `/pricing` page — both render from one config-authoritative source, `PricingCatalog::cards()`, so they can never promise different things. A tier's feature bullets live in config as an ordered list of **translation keys** (your app owns the strings, in every locale); an optional `highlight`/`badge` emphasises a card:

```php
'pro' => [
    'label' => 'Pro', /* … price as above … */
    'features'  => ['pricing.pro.projects', 'pricing.pro.priority_support'], // i18n KEYS, never raw text
    'highlight' => true,
    'badge'     => 'pricing.badge.popular',
],
```

`PricingCatalog::bulletsFor($tierKey)` resolves those keys to the current locale, in order. Because the bullets come only from config, the grid and `/pricing` cannot drift.

`TierResolver` maps a billable to its tier. The package binds **`ColumnTierResolver`** by default — it reads the denormalized `plan` column (`billing.tier_column`). If your app does not keep a tier column, rebind it to `SubscriptionTierResolver` (which maps the active price back to a tier) in one line:

```php
// A service provider
$this->app->bind(
    Pushery\Billing\Contracts\TierResolver::class,
    Pushery\Billing\Resolvers\SubscriptionTierResolver::class,
);
```

## The account hub

When `billing.enabled` is on, the hub mounts under `config('account.prefix')` (default `account/billing`) behind your `web` + `auth` middleware:

| Route | Screen |
| --- | --- |
| `/` | Overview + tier summary |
| `/subscription` | State, next-invoice preview, cancel / resume, portal link |
| `/plan` | Subscribe (hosted checkout) or in-app swap with a proration preview |
| `/payment-methods` | List, set-default, remove, add |
| `/invoices` | History + ownership-checked PDF download |
| `/usage` | Metered dimensions |
| `/usage/history` | Past periods + add-on top-up timeline |
| `/recovery` | Dunning recovery surface |
| `/danger` | Immediate cancel |
| `/portal` | Redirect to the provider's hosted billing portal |
| `/checkout/return` | Reconciles the subscription after a hosted checkout |

Add the shell banner to your layout — it renders nothing for a healthy account:

```blade
<x-billing::banner />
```

### Subscribing

A visitor becomes a subscriber from the `/plan` screen. The client submits the tier _key_ only — the price is resolved server-side (anti-price-injection) — and the package opens a hosted Checkout Session in subscription mode. The trial, provider tax and VAT-id collection, promotion codes and the billing address all ride on that session, and the card is captured (with SCA / 3-D Secure) on the provider's own page. On return, `/checkout/return` reconciles the subscription onto the local row immediately, so a paying customer is never shown "Free" while the webhook is still in flight. An owner who already subscribes swaps in-app instead of opening a second subscription.

The return URLs default to the hub's own routes; set `billing.checkout.success_url` / `cancel_url` only to override them. **Set `billing.customer.model` to your billable model** — without it, no subscription webhook can find its owner.

The hub and its emails ship translated in English, German, Spanish, French, Italian, Dutch and Portuguese, with an informal register throughout. Publish the views or the translations to customize them:

```bash
php artisan vendor:publish --tag=billing-views
php artisan vendor:publish --tag=billing-lang
```

## Webhooks

Point your provider at the configured `billing.webhook_path` (default `billing/webhook`). Deliveries are verified by signature, de-duplicated on the event id, and dispatched to registered effects. The shipped effects sync the owner's plan, credit a one-time add-on exactly once, send a dunning notice, and persist each finalized invoice. A one-time add-on's credit is mirrored onto the Stripe customer balance, so it is applied automatically against the customer's next invoice and shown to them in the account hub — not a number that only lives in the database. In production the package refuses to boot without a webhook signing secret.

## Usage-based billing

A tier can charge for usage on top of its base fee. Declare what it meters in `config/billing.php`:

```php
'pro' => [
    'label' => 'Pro',
    'provider_price' => env('BILLING_PRICE_PRO'),        // 19 €/month, the base fee
    'price_display' => ['amount' => 1900, 'currency' => 'EUR'],

    'metered' => [
        'emails' => [
            'label' => 'Emails sent',
            'unit' => 'email',
            'provider_price' => env('BILLING_PRICE_EMAILS'), // a metered price
            'provider_meter' => 'emails_sent',               // the meter it reports into
            'package_size' => 1000,                          // billed per 1 000
            'unit_price' => ['amount' => 50, 'currency' => 'EUR'],
            'included' => 10000,                             // first 10 000 free
        ],
    ],
],
```

Then record usage from your own send path — the one call does everything:

```php
app(Pushery\Billing\Support\UsageRecorder::class)->record($team, 'emails', 42_000, sourceKey: "campaign:{$campaign->id}");
```

It moves the owner's counter (what the usage screen shows) and writes the outbox row the provider is billed from, in a single local write — so what a customer sees and what they are charged for come from the same place. `billing:usage:flush` (scheduled every minute) reports it.

The parts that would cost real money if they were wrong:

- **A retried job bills once.** Pass a `sourceKey` and the same usage is recorded once, however often your job runs.
- **A retried _report_ bills once.** The provider identifier is minted when the usage is recorded and replayed unchanged, so the provider dedups it.
- **A provider outage delays billing; it does not lose it.** Reports back off and retry. Usage that truly cannot be reported is marked failed and logged as an error — never dropped quietly.
- **Usage is reported raw.** The allowance and the packaging live in the provider's price and are applied once, to the cycle's total. Configure the same `included` / `package_size` on the provider's price (a graduated tier priced at 0 up to the allowance) — the values in config drive the gauge.
- **Usage follows the subscription's cycle**, not the calendar month, so an owner who renews on the 17th is billed for the right window.

A tier that bills for usage on a driver that cannot report usage refuses to boot, rather than counting every unit and invoicing none of them.

### Prepaid units (an add-on that grants usage, not credit)

An add-on can hand the owner **units of a meter** instead of money:

```php
'addons' => [
    'extra_emails' => [
        'label' => 'Extra emails',
        'provider_price' => 'price_...',
        'price_display' => ['amount' => 3000, 'currency' => 'EUR'],
        'grants' => ['meter' => 'emails', 'units' => 1000],
    ],
],
```

- **The free allowance is spent first, the bought units only after it** — never the other way round, or the customer's own units burn while free ones sit unused.
- **`included` expires with the cycle; prepaid never does.** Paid is paid, so unused units roll forever.
- **Prepaid-covered usage is netted out before the provider is told.** The provider's price knows nothing about prepaid, so reporting the raw total would bill the customer a second time for units they had already bought. (The tier's `included` allowance is *not* netted locally — that one lives in the provider's price.)
- **A refund claws back only what is left.** Units already consumed delivered their value and stay spent; the unused remainder comes back, proportionally to the money returned.
- The reservation lock defends the prepaid balance too, so a bought unit is never handed to two concurrent requests.

Read a balance with `app(Pushery\Billing\Support\PrepaidLedger::class)->balance($owner, 'emails')`.

## Suspension & metered access

Withdraw a surface from a delinquent owner with `423 Locked` once they reach a configured dunning rung:

```php
Route::middleware('billing.suspend:api')->group(/* … */);
```

The delinquency clock is a stored timestamp, so lockout keeps working during a provider outage — and nobody is locked out unannounced: `billing:dunning:advance` (scheduled daily) walks the configured ladder, sending each rung's warning once its day arrives and charging that rung's late fee if you set one.

For the harder case — payment has actually failed (a `past_due`/`incomplete` subscription) — put `billing.dunning` on the surfaces that need a working card. A browser request is **redirected to the payment-recovery screen** (so the customer lands on "update your card", not a dead error); an API/JSON request gets `402 Payment Required` (configurable via `billing.dunning_status`). The recovery screen itself is never blocked, so there is no redirect loop. Like the suspension gate, the decision reads only the local subscription row — no provider call on the hot path.

```php
Route::middleware(['auth', 'billing.dunning'])->group(/* … the surfaces that need a paid, current card … */);
```

## Scheduled commands

The service provider registers these for you:

| Command | Cadence | What it does |
| --- | --- | --- |
| `billing:usage:flush` | every minute | Reports recorded usage to the provider that bills it |
| `billing:run` | hourly | Advances the recurring cycle (a no-op under Stripe, which drives its own) |
| `billing:dunning:advance` | daily | Walks the dunning ladder: escalating warnings + late fees |
| `billing:cards:warn` | daily | Warns owners whose card is about to expire |
| `billing:usage:reconcile` | daily | Reads the provider's usage totals back and alarms on drift or recorded-but-unbilled usage |
| `billing:prune` | daily | Ages out stored webhook payloads and expired financial records (see below) |

And these you run on demand:

```bash
php artisan billing:sync            # reconcile subscriptions from the provider onto the local rows
php artisan billing:install         # publish the config + generate the owner-columns migration
php artisan billing:webhooks:replay --failed   # re-drive webhook effects that failed
php artisan billing:erase {owner}   # erase an owner's billing data (see Personal data)
php artisan billing:export {owner}  # everything the package holds about one owner, as JSON
php artisan billing:doctor          # check your Stripe webhook endpoints render the pinned API version
php artisan billing:meters:check    # verify every configured usage meter exists and is active at the provider
php artisan billing:usage:reconcile --redrive  # (also scheduled daily) retry the rollups a flush gave up on
php artisan billing:tier:grant {owner} {tier}  # comp an owner onto a tier out of band, recorded on the audit trail
php artisan billing:datev:export    # a period of invoices as a DATEV EXTF booking batch (defaults to last month)
```

`billing:meters:check` catches a metered tier whose `provider_meter` was never created, or was archived, at the provider — usage reported into a meter that does not exist fails silently, and the miss surfaces (if ever) as an under-charged invoice a month later. It exits non-zero when a meter is missing, so it fits a deploy check. `billing:usage:reconcile` answers "is there any recorded-but-unbilled usage right now?" — after fixing the cause (often a meter `billing:meters:check` found), `--redrive` returns the failed rollups to pending so the next flush retries them.

`billing:sync` is the bulk version of the post-checkout reconcile — use it to backfill after a webhook outage. It applies each subscription through the same plan-sync effect the webhook uses, so it can never overwrite a newer webhook state; it only moves a stale local row forward. Scope it with `--owner`, preview with `--dry-run`.

`billing:tier:grant` is the terminal form of a support comp (the same `BillingAdmin::comp` an admin panel calls): it writes the tier column directly and records the grant on the audit trail. It refuses a tier key no `billing.tiers` entry declares, and warns when the tier is not in `billing.untouchable_tiers` — because the next provider webhook is otherwise free to overwrite the grant. `billing:datev:export` writes a period of issued invoices as a DATEV EXTF "Buchungsstapel" (the booking batch a German tax advisor imports); with no `--from`/`--to` it exports the previous calendar month, so a monthly cron hands last month's bookings to the Steuerberater. The account numbers come from `billing.datev` and must be confirmed with the advisor — left unset the file is structurally valid with blank account fields.

### Already using Cashier?

Your billables already carry `stripe_id` — the very column this package reads. So after installing, run one command to adopt every subscription you already have:

```bash
php artisan billing:sync
```

It pulls each existing customer's live subscription from Stripe and writes the local row and tier column. Without it your paying customers stay invisible to the package until their next `customer.subscription.*` webhook — which, on an untouched annual plan, could be a year away.

### Migrating from your own billing code

Already have a hand-rolled billing namespace — your own subscription model, webhook controller, invoice logic? Adopt the package in place, one seam at a time, with no big-bang cutover:

1. **Install alongside your code.** Run the [installer](#installation). The package's own tables (`billing_subscriptions`, `billing_invoices`, `billing_usage_counters`, …) are prefixed, so they sit next to yours without colliding — nothing of yours is touched yet.
2. **Point it at your billable.** Set `billing.owner` (the actor that owns billing — the user, or its team) and `BILLING_CUSTOMER_MODEL` to your model. The owner is resolved through the `BillingEntityResolver` contract; bind your own implementation if the mapping is non-trivial.
3. **Backfill live state.** Run `php artisan billing:sync` so every existing Stripe subscription lands in the local rows immediately — not a year from now (see [above](#already-using-cashier)).
4. **Move the webhook.** Point your Stripe endpoint at `billing/webhook` (the `billing.webhook_path`) and retire your own controller. The package verifies the signature, dedups redeliveries, and translates each event into a neutral domain event + effect — the part hand-rolled billing most often gets subtly wrong.
5. **Swap reads and writes to the contracts.** Replace calls into your billing namespace with the package contracts resolved from the container — `SubscriptionActions` (cancel / resume / swap), `Invoices` (history + download), `UsageProvider` (current usage). Your controllers and views stop touching your own billing models.
6. **Delete the old namespace.** Once nothing resolves it, remove the code, its migrations (the data now lives in the package tables), routes and tests. If your old code did **more** than the package (a bespoke report, an export), capture that gap before deleting so the capability isn't quietly lost.

Each step is independently shippable, and the package runs beside your code until you retire the last of it.

## Audit trail

Every money movement and entitlement change is recorded on an append-only audit ledger with WHO did it —
the specific actor and its category (customer / admin / webhook / system), not just what happened. So "why is
this customer on Free?" has an answer: the plan was revoked by a past-due webhook, or canceled by a support
agent, or by the customer themselves.

```php
use Pushery\Billing\Models\BillingEvent;

BillingEvent::query()
    ->where('subject_type', $user->getMorphClass())->where('subject_id', $user->getKey())
    ->latest()->get();  // this owner's billing history, newest first
```

The ledger is **append-only**: a row can never be updated, and can only be deleted by the retention prune or
an erasure request — an ad-hoc write throws, so the trail stays tamper-evident. The default level (`money`)
records every money and entitlement event and is never noisy; set `billing.audit.level` to `all` to also
record navigational and high-volume events (checkout opened, card added). `billing:prune` ages the ledger out
on its own retention window (`billing.retention.audit_days`, default 10 years — the bookkeeping window;
weigh it against GDPR storage limitation for your case), and `billing:export` includes an owner's audit
history in their subject-access export.

## Metrics

A point-in-time snapshot for your own admin dashboard — MRR and the subscription counts — computed from the
local rows, with no provider round-trip:

```php
$metrics = app(Pushery\Billing\Reporting\BillingMetricsReporter::class)->compute();

$metrics->mrr;                 // Money — monthly-recurring revenue
$metrics->activeSubscriptions; // int
$metrics->trials;              // int — on trial now
$metrics->inDunning;           // int — past due / walking the dunning ladder
$metrics->canceledInWindow;    // int — churned within the trailing window ($metrics->windowDays, default 30)
```

MRR is **declared list price, monthly-normalized** (a yearly tier ÷ 12), summed in `billing.currency` — what
your catalog says you charge, deliberately independent of a provider coupon or mid-cycle proration. It assumes
a single billing currency; a free tier (no `price_display`) contributes nothing. Pass a window to `compute($days)`
to measure churn over a different span.

## Personal data

The package stores personal data on your behalf — the customer's name and address on an invoice, and the raw
provider webhook payloads, which carry their email, name, billing address and the last four digits of their
card. So it ships the two things a GDPR request actually needs.

```bash
php artisan billing:export 42            # everything we hold about owner 42, as JSON (Art. 15 / Art. 20)
php artisan billing:erase 42             # erase it (Art. 17)
php artisan billing:erase 42 --dry-run   # …or see first what would go and what would stay
```

**`billing:erase` deliberately does not delete the invoices.** A valid invoice has to carry the buyer's name
and address (§14 UStG), and invoices have to be kept for years (§147 AO, §14b UStG) — the right to erasure
yields to a legal retention obligation (Art. 17(3)(b)). Those rows are unlinked from the owner and kept, and
`billing:prune` removes them once the retention window closes (`billing.retention.erased_financial_days`,
default ten years — check it against your own jurisdiction). Everything else goes: subscriptions, usage,
credit balances, the owner's own stored provider API keys, and the personal data inside the webhook payloads.

A credit balance is money you still owed the customer, so it is written to the audit ledger before it is
purged rather than vanishing quietly.

`billing.erasure.forget_customer` additionally DELETES the customer at the provider. That is irreversible and
it cancels their live subscriptions there, so it is **off by default** — turn it on deliberately. Stripe keeps
its own invoice and charge records regardless.

**The command is the complete path.** An observer on your `User` model would look more convenient, but a mass
delete (`User::query()->where(...)->delete()`) fires no model events at all — an app relying on one would
under-erase and never know. Call `billing:erase` (or the `BillingEraser`) from wherever you handle the
request.

`billing:prune` also ages out the stored webhook payloads on its own clock
(`billing.retention.webhook_payload_days`, default 90). They exist for exactly one reason — so a failed effect
can be re-driven from what the provider already sent — and the provider itself stops redelivering after about
three days. A payload whose effects are still owed is never pruned, however old it is.

## E-invoicing

Every invoice Stripe finalizes is persisted as an immutable `InvoiceRecord` — its number, its buyer snapshot
(name, address, VAT id, frozen at finalization as §14 UStG requires) and its line and tax breakdown — by a
webhook effect. That stored row is what the e-invoicing renderers read; nothing needs to be populated by hand.

When money goes back, the credit note Stripe issues (`credit_note.created`) is persisted the same way, linked
to the invoice it credits. DATEV books it as a Haben (credit), and XRechnung renders it as an EN 16931 credit
note (type code 381) referencing the original invoice — amounts stay positive, the document type carries the
credit meaning.

Render a stored invoice as an EN 16931 / XRechnung document, or export a batch to DATEV:

```php
$xml = app(Pushery\Billing\Contracts\EInvoice::class)->render($invoice);

$csv = app(Pushery\Billing\Invoicing\DatevExport::class)
    ->export($invoices, $from, $to);
```

Set your company details under `config('billing.company')` and your DATEV account numbers under `config('billing.datev')`. ZUGFeRD (this XML embedded in a PDF/A-3) plugs into the same `EInvoice` contract.

## Feature reference

The complete surface, by area. Everything below ships today on the Stripe driver and is exercised by the test suite.

**Subscriptions & checkout**
- Hosted Checkout in subscription mode — the client submits a tier _key_, never a price (anti-price-injection); trial, provider tax, VAT-id collection, promotion codes and billing address all ride the one session.
- In-app upgrade/downgrade **swap** with a proration **preview**; cancel-into-grace, resume, cancel-now.
- Post-checkout reconcile onto the local row on return (never "Free" while a webhook is in flight).
- **Trials** — `none` / `subscription` / `generic`, global or per-tier, card required or `if_required`; trial-ending reminder.
- Grandfathering via per-tier `legacy_prices`; multi-item subscriptions handled (the tier item resolved by price, foreign items untouched).

**Payment methods & money movement**
- Setup-intent, list (default first), set-default, remove — card capture on the provider's hosted page (**PCI SAQ-A**, no card data touches your app).
- `PaymentRails`: on-session charge, off-session (merchant-initiated) charge, stored-mandate creation, tokenization, refund — all idempotency-keyed, all provider-neutral `Money`.
- Fail-closed **eligibility gate** (`CanTransactMoney`) in front of every money-moving surface.
- Account **credit balance** mirrored onto the provider balance (`CreditSync`), spendable and shown in the hub.

**Usage-based billing & metering**
- Record usage in one call — per-meter counters + provider outbox in a single local write, bucketed by the subscription cycle.
- Report to the provider (`UsageReporter`); a retry cannot double-bill, an outage cannot silently lose revenue.
- **Quota enforcement** — `billing.quota:<meter>` middleware + `UsageGate`, four policies (hard-stop / refuse / degrade / fair-use).
- **Oversell-safe** reserve→work→settle via `UsageRecorder::meter()` (row-lock hold, expiring, reclaimed by flush).
- **Prepaid units** — an add-on grants meter units (not money); free allowance spent first, prepaid never expires, netted before reporting, proportional clawback on refund.
- Drift guards: `billing:meters:check` (meter exists/active + price is meter-backed) and `billing:usage:reconcile` (provider read-back → drift/stall events).

**Dunning, suspension & tax**
- Config-driven multi-level **dunning ladder** (`billing:dunning:advance`) with per-rung late fees (`LateFees`).
- Per-surface **`423 Locked` suspension** driven by a stored delinquency clock (outage-safe); a pause is never treated as delinquency.
- **Tax** — provider tax (Stripe Tax) flows onto the persisted invoice; a pluggable `TaxCalculator` seam (EU-OSS table / none) is bound for a local computation path. Intra-EU B2B **reverse charge** is rendered as EN 16931 category `AE`.

**Invoices, e-invoicing & accounting**
- Every finalized invoice persisted as an **immutable `InvoiceRecord`** (GoBD — number, amounts, currency, tax, date, lines frozen).
- **EN 16931 / XRechnung** (UBL) writer, dependency-free; **credit notes** (type 381) linked to the original.
- **DATEV** EXTF "Buchungsstapel" export.

**Account hub — 9 Livewire screens**
- `overview` · `subscription` · `plan` (change/subscribe) · `payment-methods` · `invoices` · `usage` · `usage/history` · `recovery` · `danger`, plus the hosted-portal bridge and the `checkout/return` reconcile.
- Config-driven routes + navigation, master-switch gated, scoped CSP, a `<x-billing::banner />` app-shell banner, seven shipped locales (informal register).

**Webhooks**
- Signature-verified, idempotent, **per-effect queued jobs**, a raw-payload ledger and `billing:webhooks:replay`.
- 13 shipped effects (plan-sync, subscription-activated + subscription-canceled notices, add-on credit, dunning notice, payment-action-required nudge, payment receipt, invoice + credit-note persistence, usage force-flush, add-on reversal, mandate revoke, trial-ending) mapped from the provider-neutral domain events.

**Seats & entitlements**
- Owner = **User XOR Team**; `HasSeats` + provider-neutral `SeatSync` (fail-loud below occupied seats).
- **`License`** gate — boolean feature grants + numeric limits, separate from pricing, fail-closed, stateless.
- **`EntitlementsResolver`** — the owner-facing limit guard: `limit($owner, $key)`, `remaining($owner, $key, $used)`, `allows($owner, $key, $used, $delta = 1)` (uncapped → always allows; else `used + delta <= limit`). Enforce a ceiling the same way everywhere instead of re-writing the `<`/`<=` comparison per call site — the count of what's used stays your `UsageProvider`'s job, only the comparison lives here.

**Security, data protection & operations**
- `billing.enabled` master switch (the whole surface disappears, incl. Cashier routes), scoped CSP, per-screen fail-closed auth, ownership checks on payment-method verbs.
- **GDPR** — `billing:erase` / `billing:export` / `billing:prune`; erasure retains invoices (statutory), scrubs webhook-payload PII, banks owed credit to the audit log.
- **Audit trail** — append-only ledger recording WHO (actor + source: customer/admin/webhook/system) did every money and entitlement change.
- **Admin console** — `BillingAdmin` (comp / cancel / refund), UI- and auth-agnostic.
- The Stripe **API version is pinned by the package** (not inherited from the SDK); `billing:doctor` checks your endpoints match it.

**Scheduled + on-demand commands** — see [Scheduled commands](#scheduled-commands) for all 15.

## Drivers

| Capability | Stripe (shipping) | Mollie / Adyen (planned) |
| --- | --- | --- |
| Subscriptions, proration, trials | native | package-local engine |
| Invoices / PDF | native | generated locally |
| Hosted portal | native | not available |
| Webhooks | signed | bare-id / HMAC |

The neutral contracts and the `BillingEngine::tick()` seam ship today; the Mollie and Adyen drivers themselves are not built. Under Stripe, `tick()` is a deliberate no-op — Stripe drives its own recurring cycle.

### The Cashier coupling, and where it stops

The Stripe driver builds on the raw `stripe/stripe-php` SDK, not on Cashier's models or its `Billable` trait — the package reimplements checkout, the portal, invoices, subscriptions and payment methods itself so the same neutral contracts can back a future non-Cashier driver. Cashier is used for exactly two things, both confined to the driver layer: it supplies the `cashier.*` config namespace the driver reads Stripe credentials from (`cashier.secret`, `cashier.webhook.secret`), and `Cashier::ignoreRoutes()` is what the master switch calls to drop Cashier's own routes when billing is off. The one place a raw Cashier/provider object is intentionally passed straight through is the hosted invoice-PDF download response, which is streamed as the provider returns it. An architecture test (`tests/Unit/ArchTest.php`) fails the build if any `Stripe\` or `Laravel\Cashier\` import, or a hardcoded `->stripe_id` read, leaks outside `src/Drivers/Stripe/`, so this boundary cannot erode.

## Testing

```bash
composer test        # unit + feature + cross-engine (Postgres + MySQL)
composer qa          # style, static analysis, type + line coverage
```

### Testing your own billing logic

You do not need to reach Stripe to test the code you build on this package. Two seams make it straightforward.

**Assert on the neutral domain events.** Every webhook the package processes is translated into a
provider-neutral event (`SubscriptionStateChanged`, `AddonPurchased`, `PaymentFailed`, `InvoiceFinalized`,
`MandateRevoked`, …) and dispatched through Laravel's own dispatcher, so `Event::fake()` and
`Event::assertDispatched()` work exactly as they do for your app's events:

```php
use Illuminate\Support\Facades\Event;
use Pushery\Billing\Events\SubscriptionStateChanged;

Event::fake([SubscriptionStateChanged::class]);

// … deliver a webhook, or run a reconcile …

Event::assertDispatched(SubscriptionStateChanged::class,
    fn (SubscriptionStateChanged $e) => $e->tierKey === 'pro');
```

**Fake the outbound actions.** The provider-mutating seams are contracts, so bind a fake to keep a test off
the network and assert on what your code asked for:

```php
use Pushery\Billing\Contracts\SubscriptionActions;

$actions = Mockery::spy(SubscriptionActions::class);
$this->app->instance(SubscriptionActions::class, $actions);

// … exercise the code that swaps a plan …

$actions->shouldHaveReceived('swap')->with($user, 'pro', true);
```

The same applies to `Checkout` (open a subscription) and `OneTimeCharge` (buy an add-on). No card data,
no live call, no webhook secret needed.

**Or use the recording fake.** For the common case, `Billing::fake()` binds a recording fake to all three
seams at once and gives you ready-made assertions — the same shape as `Bus::fake()`:

```php
use Pushery\Billing\Facades\Billing;

Billing::fake();

// … exercise the code that subscribes the user and buys an add-on …

Billing::assertSubscribeStarted($user, 'pro');
Billing::assertSwapped($user, 'premium');
Billing::assertNothingCharged();
```

## Security

Please review the [security policy](SECURITY.md) and report vulnerabilities privately rather than opening a public issue.

## Built by Pushery

This package is built and maintained by [Pushery](https://www.pushery.com) — a Berlin-based studio building Laravel applications, SaaS products, and open-source tools.

Building a Laravel UI? [WireKit](https://wirekit.app), Pushery's open-source Livewire component kit, gives you a polished component library out of the box. Browse the rest of our work at [pushery.com](https://www.pushery.com).

## License

The MIT License (MIT). See [LICENSE](LICENSE) for details.
