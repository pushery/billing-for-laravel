# Configuration reference

Every key the package publishes, its environment variable and its shipped default. The tables follow the
order of the published files, so you can read this beside your own `config/billing.php`.

`billing:install` publishes all three files; `vendor:publish --tag=billing-config` re-publishes them. A key
you never touch keeps the default listed here — the package merges its own config underneath yours, so a
published file missing a key is not an error.

A key with an environment variable can be set from `.env` **or** edited in the published file. A key without
one is edited in the file, because its value is a structure rather than a scalar.

> **Set `billing.customer.model`.** It is the one key with no useful default: without it no subscription
> webhook can resolve its owner. See [Choosing your setup](../choosing-your-setup.md).

## `config/billing.php`

### Master switch and driver

| Key | Env | Default | What it does |
| --- | --- | --- | --- |
| `billing.enabled` | `BILLING_ENABLED` | `true` | The master switch. Off, the manager resolves the no-op `NullDriver`, no account routes are registered and no billing surface exists — a clone without billing boots and schedules cleanly. |
| `billing.default` | `BILLING_DRIVER` | `stripe` | The payment driver used when none is named. |
| `billing.stripe.api_version` | `BILLING_STRIPE_API_VERSION` | `null` | The dated Stripe API version every call and webhook runs against. Null uses the version the package pins and is tested against. Override it only deliberately: Stripe versions the *shape* of a webhook payload, so a newer version can silently change what a mapper reads. |

### Webhooks

| Key | Env | Default | What it does |
| --- | --- | --- | --- |
| `billing.webhook_path` | `BILLING_WEBHOOK_PATH` | `billing/webhook` | The path the provider posts to. The route carries no middleware group and no CSRF — the driver's verifier authenticates by signature instead. |
| `billing.webhooks.connection` | `BILLING_WEBHOOK_QUEUE_CONNECTION` | `null` | Queue connection for webhook effects. Null uses the default connection. |
| `billing.webhooks.queue` | `BILLING_WEBHOOK_QUEUE` | `null` | Queue name for webhook effects. Set it to keep billing work off the queue your app's other jobs share. |
| `billing.webhooks.tries` | `BILLING_WEBHOOK_TRIES` | `5` | How often a failing effect is retried before the job is marked failed. It stays re-driveable after that — the raw payload is stored, so `billing:webhooks:replay --failed` can run it long after the provider stopped redelivering. |

Each effect runs in its own queued job, so a slow or failing effect can neither hold the provider's request
open nor take the other effects down with it. See [Webhooks](../single-seller/webhooks.md).

### Checkout

| Key | Env | Default | What it does |
| --- | --- | --- | --- |
| `billing.checkout.success_url` | `BILLING_CHECKOUT_SUCCESS_URL` | `null` | Where a hosted checkout returns on success. Null uses the hub's checkout-return route, which reconciles the subscription onto the local row so a paying customer is never shown the free tier. |
| `billing.checkout.cancel_url` | `BILLING_CHECKOUT_CANCEL_URL` | `null` | Where an abandoned checkout returns. Null uses the plan screen. |
| `billing.checkout.portal_return_url` | `BILLING_PORTAL_RETURN_URL` | `null` | Where the hosted billing portal returns. Falls back to `success_url`, then the subscription screen. |
| `billing.checkout.promotion_codes` | `BILLING_CHECKOUT_PROMOTION_CODES` | `true` | Whether the provider's promotion-code field is offered at checkout. |

### Owner model and seats

| Key | Env | Default | What it does |
| --- | --- | --- | --- |
| `billing.owner` | `BILLING_OWNER` | `user` | Who owns billing: `user` (each user pays for themselves) or `team` (the user's team pays, for seats). |
| `billing.team_relation` | — | `team` | With a team owner, the relation on the acting user that returns the paying team. Ignored for a user owner. |
| `billing.seats.membership_relation` | — | `members` | The relation on the team model that returns its members. `HasSeats` counts it. |
| `billing.seats.active_status_column` | — | `null` | Column to filter the member count to active members. Null when the relation already returns only active ones — a pending invite is not a paid seat. |
| `billing.seats.active_status_value` | — | `active` | The value that column must hold to count. |
| `billing.seats.membership_events` | — | `[]` | **Your** join, leave and remove events. A queued listener is registered on each, so a membership change re-syncs the billed quantity. Empty means nothing fires until you opt in. |
| `billing.seats.owner_properties` | — | `['team', 'owner']` | For an event that does not implement `AffectsSeats`, the first of these properties holding the team model is read. |

### Customer and tier resolution

| Key | Env | Default | What it does |
| --- | --- | --- | --- |
| `billing.customer.model` | `BILLING_CUSTOMER_MODEL` | `null` | The Eloquent model that owns a provider customer record. Set it before you take a real payment. |
| `billing.customer.column` | — | `stripe_id` | The column holding the provider's customer reference. |
| `billing.zero_tier` | `BILLING_ZERO_TIER` | `free` | The fail-safe no-entitlement tier every resolver falls back to. |
| `billing.tier_column` | — | `plan` | The raw column `ColumnTierResolver` reads — never an accessor. |
| `billing.untouchable_tiers` | — | `[]` | Tier keys the plan-sync webhook effect never flips, in either direction. Use it for an admin-comped grant a provider event must not overwrite. |

### Tiers, add-ons and coupons

| Key | Env | Default | What it does |
| --- | --- | --- | --- |
| `billing.tiers` | — | one `free` tier | The tier catalog, keyed by tier key; the **order is the upgrade ranking**. Per tier: `label`, an optional `provider_price` (an id, or a per-driver map), `price_display`, `interval`, `legacy_prices`, `metered` components, `trial`, and the presentation-only `features`, `highlight` and `badge`. A tier with no `price_display` is not purchasable. The client submits a tier **key**, never a price. |
| `billing.addons` | — | `[]` | One-time purchasable add-ons, keyed by add-on key: `label`, `provider_price`, `price_display`, and an optional `grants` (`meter` plus `units`). An add-on grants either money credit or prepaid usage units. |
| `billing.coupons` | — | `[]` | Package-owned discount codes keyed by the code the customer enters: either `percent` (1 to 100) or `amount` plus `currency`, with an optional `expires_at`. Add `stripe_coupon` to have the provider own the money math at checkout. |
| `billing.currency` | `BILLING_CURRENCY` | `EUR` | Used where an amount carries no explicit currency, such as a zero dunning fee. |

The shape of a tier is worked through in [Tiers and pricing](../single-seller/tiers-and-pricing.md); add-ons
and prepaid units in [Usage-based billing](../single-seller/usage-based-billing.md).

### Usage and metering

| Key | Env | Default | What it does |
| --- | --- | --- | --- |
| `billing.dimensions` | — | `[]` | Extra dimensions a **custom** `UsageProvider` reports: `label`, `unit`, `period`, `warn_threshold`. The default provider ignores this — it derives its dimensions from each tier's `metered` components, so an owner sees exactly what they are billed for. |
| `billing.metering.max_attempts` | — | `8` | A deadline, not a limit: past it the usage is marked failed and logged as an error, because it is revenue that will not be collected unless someone acts. Do not raise it to hide a persistent failure. |
| `billing.metering.backoff_seconds` | — | `60` | Base delay for the exponential backoff between flush attempts. |
| `billing.metering.stall_hours` | — | `6` | How long usage may sit unreported before `billing:usage:reconcile` calls it a stall rather than a passing outage. Keep it under your provider's back-dated acceptance window. |
| `billing.usage.hold_seconds` | `BILLING_USAGE_HOLD_SECONDS` | `900` | How long a hold on a metered allowance stands before it is handed back. Longer than your slowest metered request, shorter than you would tolerate an owner being short of allowance they never spent. |
| `billing.quota.status` | — | `429` | The HTTP status the `billing.quota:<meter>` middleware aborts a blocked request with. Only a blocking meter is gated; a degrade or fair-use meter never is. |

### Dunning, suspension and notifications

| Key | Env | Default | What it does |
| --- | --- | --- | --- |
| `billing.dunning` | — | three rungs, at 3, 7 and 14 days | The ladder, in order. Each rung: `after_days`, an optional `fee` (`amount` plus `currency`), an optional `label`. `billing:dunning:advance` sends each rung's warning once and charges its fee if one is set. The delinquency clock is a timestamp, never a gateway status. |
| `billing.dunning_status` | — | `402` | The status a non-browser request from a delinquent owner gets. A browser request is redirected to the recovery screen instead. |
| `billing.suspension` | — | `[]` | Per-surface lockout thresholds keyed by surface name; the value is the dunning level at which that surface locks. A surface with no threshold never locks. |
| `billing.cards.warn_within_days` | — | `30` | How far ahead `billing:cards:warn` nudges an owner whose default card is expiring — the biggest preventable cause of involuntary churn. |
| `billing.notifications.channels` | — | `['mail']` | The transport only, never whether the customer is told: billing notices are transactional and non-suppressible. Add `database` for an in-app feed. An unusable value falls back to mail rather than sending nothing. |

### Account hub, admin and runtime

| Key | Env | Default | What it does |
| --- | --- | --- | --- |
| `billing.navigation` | — | the full hub | The hub's sections, keyed by item key: `label` (an i18n key or a literal), `route`, and the optional `group`, `icon`, `order` and `web_only`. Malformed items are dropped. Remove an entry and the section is gone. |
| `billing.runtime` | `BILLING_RUNTIME` | `web` | `web` or `native`. On `native`, items flagged `web_only` are hidden — for flows an app store forbids from being completed in-app. |
| `billing.link_out` | `BILLING_LINK_OUT` | `null` | The external billing portal to link out to when an external merchant of record owns billing. Scheme-restricted: anything but an absolute `http` or `https` URL with a host is ignored. |
| `billing.realtime.enabled` | `BILLING_REALTIME` | `false` | Opt-in live refresh for the hub. Events broadcast only when this is on **and** a broadcaster is configured; otherwise the screens fall back to a bounded poll. |
| `billing.admin.ability` | `BILLING_ADMIN_ABILITY` | `billing-admin` | The Gate ability every admin-console access is authorized against. **Your app defines it**; until you do, the Gate denies everyone. |
| `billing.admin.prefix` | `BILLING_ADMIN_PREFIX` | `admin/billing` | The URL prefix the admin console mounts under. |
| `billing.admin.middleware` | — | `['web', 'auth']` | The middleware stack the admin console runs through. |
| `billing.subscriptions.downgrade_timing` | `BILLING_DOWNGRADE_TIMING` | `period_end` | When a downgrade lands. An upgrade is always immediate. `period_end` avoids owing a refund or taking away paid-for access mid-cycle; `immediate` downgrades at once. The screen and the swap read this one value, so they cannot disagree. |

### Trials

| Key | Env | Default | What it does |
| --- | --- | --- | --- |
| `billing.trial.days` | `BILLING_TRIAL_DAYS` | `0` | Trial length in days; `0` disables trials. Per-tier override: `tiers.<key>.trial.days`. |
| `billing.trial.mode` | `BILLING_TRIAL_MODE` | `null` | `none`, `subscription` (collected at checkout) or `generic` (no subscription, granted by `Trials::grant()`). Null derives it: a configured `generic_tier` implies `generic`, otherwise a positive length implies `subscription`. |
| `billing.trial.generic_tier` | `BILLING_TRIAL_GENERIC_TIER` | `null` | The tier a generic trial unlocks. Null disables generic trials — without a tier to unlock there is nothing to grant. |
| `billing.trial.requires_payment_method` | `BILLING_TRIAL_REQUIRES_PM` | `true` | Whether a subscription trial collects a card up front. |
| `billing.trial.ending_within_days` | — | `3` | How many days before a trial ends the app-shell banner starts nudging. |

### Tax and the invoice seller

| Key | Env | Default | What it does |
| --- | --- | --- | --- |
| `billing.tax` | `BILLING_TAX` | `none` | `provider` (defer to a provider that supports it), `eu_oss` (the bundled static EU-OSS VAT table) or `none`. A driver-capability decision, not a checkout option. |
| `billing.company.name` | `BILLING_COMPANY_NAME` | `null` | The seller party on an e-invoice. |
| `billing.company.vat_id` | `BILLING_COMPANY_VAT_ID` | `null` | Your VAT identification number. Also the last fallback for the electronic address below. |
| `billing.company.address` | `BILLING_COMPANY_ADDRESS` | `null` | Street address of the seller party. |
| `billing.company.postcode` | `BILLING_COMPANY_POSTCODE` | `null` | Postal code of the seller party. |
| `billing.company.city` | `BILLING_COMPANY_CITY` | `null` | City of the seller party. |
| `billing.company.country` | `BILLING_COMPANY_COUNTRY` | `DE` | Two-letter country code of the seller party. |
| `billing.company.endpoint_id` | `BILLING_COMPANY_ENDPOINT_ID` | `null` | The seller electronic address (EN 16931 BT-34). XRechnung makes it mandatory, so an endpoint must always resolve: set it, or the renderer falls back to a company email, then to `vat_id`. |
| `billing.company.endpoint_scheme` | `BILLING_COMPANY_ENDPOINT_SCHEME` | `EM` | The scheme code for that address (`EM` is email). |

Tax and the invoice surface: [Taxes](../single-seller/taxes.md) ·
[Invoices and e-invoicing](../single-seller/invoices-and-e-invoicing.md).

### Data protection and retention

| Key | Env | Default | What it does |
| --- | --- | --- | --- |
| `billing.audit.level` | `BILLING_AUDIT_LEVEL` | `money` | `money` records every money movement and entitlement or state change — the events an auditor, or a "why is this customer on free?" question, needs. `all` adds the high-volume navigational and read-side events. |
| `billing.erasure.forget_customer` | `BILLING_ERASURE_FORGET_CUSTOMER` | `false` | Whether `billing:erase` also deletes the customer at the provider. Irreversible, and it cancels their live subscriptions there — off by default. |
| `billing.retention.webhook_payload_days` | `BILLING_RETENTION_WEBHOOK_PAYLOAD_DAYS` | `90` | How long a stored webhook payload is kept. Long past the provider's own redelivery window, which is the only reason it is kept at all. |
| `billing.retention.erased_financial_days` | `BILLING_RETENTION_ERASED_FINANCIAL_DAYS` | `2920` | The invoice window for an erased owner's retained invoices: eight years, counted from the **end** of the year of issue. A shorter value refuses to boot. A floor and a default — set your own for another jurisdiction. |
| `billing.retention.audit_days` | `BILLING_RETENTION_AUDIT_DAYS` | `3650` | The book window for the audit ledger: ten years. Deliberately longer than the invoice window above — two record classes, not a value that drifted. Do not unify them. |
| `billing.retention.allow_below_statutory_minimum` | `BILLING_RETENTION_ALLOW_BELOW_STATUTORY_MINIMUM` | `false` | The escape hatch for a jurisdiction whose invoice minimum genuinely is shorter than the floor above. Left `false`, a shorter `erased_financial_days` refuses to boot rather than prune tax records early. |

Erasure keeps invoices on purpose: a valid invoice has to carry the buyer's name and address and has to be
kept for years, so the right to erasure yields to the retention obligation. Those rows are unlinked from the
owner and removed by `billing:prune` once the window closes. See
[Data protection](../single-seller/data-protection.md) and
[Retention and erasure](../compliance/retention-and-erasure.md).

### DATEV export

| Key | Env | Default | What it does |
| --- | --- | --- | --- |
| `billing.datev.consultant` | `BILLING_DATEV_CONSULTANT` | `null` | The consultant number in the EXTF header. |
| `billing.datev.client` | `BILLING_DATEV_CLIENT` | `null` | The client number in the EXTF header. |
| `billing.datev.account_length` | `BILLING_DATEV_ACCOUNT_LENGTH` | `4` | The account-number length your chart uses. |
| `billing.datev.revenue_account` | `BILLING_DATEV_REVENUE_ACCOUNT` | `null` | The revenue account every invoice books against when no chart is selected. Left empty, the export still produces a structurally valid file with blank account fields. |
| `billing.datev.customer_account` | `BILLING_DATEV_CUSTOMER_ACCOUNT` | `null` | The receivables account every invoice books to when no chart is selected. |
| `billing.datev.chart` | `BILLING_DATEV_CHART` | `null` | `skr03`, `skr04` or null. Null uses the two accounts above and the export is byte-identical. Selecting a chart changes only the values resolved per transaction, never the file's structure or field order. |
| `billing.datev.accounts` | — | an SKR03 and an SKR04 map | The per-transaction account map for each chart. Each entry is `account` plus `automatic`; an automatic account derives its VAT from the posting itself, so a tax key is never set alongside it. The numbers are German-accountant defaults, not values the package invents — override them for a different frame without a code change. |

These are specific to your chart of accounts and your tax advisor's setup. Confirm them before importing.
See [Accounting and DATEV](../single-seller/accounting-and-datev.md).

### Multi-merchant keys

The package ships the single-merchant path. These keys exist because two guarantees have to be enforceable
before a routed sale could ever exist, and both are enforced today: a posture cannot be resolved outside the
list you opted into, and the platform cannot start holding other people's money by flipping a flag.

| Key | Env | Default | What it does |
| --- | --- | --- | --- |
| `billing.marketplace.enabled` | `BILLING_MARKETPLACE_ENABLED` | `false` | Off, the custody guard below has nothing to check and single-merchant behavior is byte-identical. |
| `billing.marketplace.custody.platform_held` | `BILLING_MARKETPLACE_PLATFORM_HELD` | `false` | Whether the platform itself would hold other people's funds. Holding funds is a regulated activity in most jurisdictions, so this is refused **at boot** unless the host binds a `PaymentServiceLicenseAttestation`. A flag alone can never make an unaware consumer an unlicensed money holder. There is deliberately no interest or yield option. |
| `billing.marketplace.seller_of_record.default_posture` | `BILLING_MARKETPLACE_POSTURE` | `platform_deemed_supplier` | Who the seller is to the buyer. A liability and VAT decision the package enforces but never makes for you. |
| `billing.marketplace.seller_of_record.allowed_postures` | — | `['platform_deemed_supplier']` | The postures you have deliberately opted into. Resolving one outside this list is refused. |
| `billing.marketplace.seller_of_record.supplies_are_electronic` | `BILLING_MARKETPLACE_SUPPLIES_ELECTRONIC` | `true` | Default classification of what is sold. An electronically supplied service falls under the deemed-supplier presumption; physical goods do not. |
| `billing.marketplace.seller_of_record.art9a_rebuttal_asserted` | `BILLING_MARKETPLACE_ART9A_REBUTTAL` | `false` | The rebuttal assertion. It and the three keys below must all be true before a seller-of-record posture is allowed for an electronic supply. |
| `billing.marketplace.seller_of_record.no_agb_control` | — | `false` | Assert that you do not set the terms of the supply. |
| `billing.marketplace.seller_of_record.no_billing_authorization` | — | `false` | Assert that you do not authorize the charge to the buyer. |
| `billing.marketplace.seller_of_record.no_supply_authorization` | — | `false` | Assert that you do not authorize the delivery of the supply. |
| `billing.marketplace.fee.rounding` | `BILLING_MARKETPLACE_FEE_ROUNDING` | `platform_first` | Which side of an uneven percentage split keeps the leftover minor unit. `platform_first` gives it to the fee; `creator_first` gives it to the net, the only order that hits an exact target payout. At volume that assignment is real money, so it is a documented contract choice rather than an accident of rounding. |

A platform that sets its own terms, authorizes billing or approves the supply cannot truthfully assert the
three `no_*` keys. Leave them false.

## `config/account.php`

| Key | Env | Default | What it does |
| --- | --- | --- | --- |
| `account.prefix` | `BILLING_ACCOUNT_PREFIX` | `account/billing` | The URL prefix the hub mounts under. The whole hub is gated on `billing.enabled`. |
| `account.middleware` | — | `['web', 'auth']` | The middleware stack the hub routes run through. The hub shows a signed-in owner their own billing. |
| `account.layout` | `BILLING_ACCOUNT_LAYOUT` | `billing::layouts.account` | The Blade layout the full-page screens extend. Point it at your own layout to frame the hub in your chrome. |
| `account.csp.enabled` | `BILLING_ACCOUNT_CSP` | `true` | The scoped Content-Security-Policy, so the driver's payment element loads on the billing screens only. Turn it off only if your app already sends its own CSP for these routes — browsers enforce every CSP header at once. |
| `account.csp.additional` | — | `[]` | Extra origins to allow, keyed by directive (for example `'font-src' => ['https://fonts.example']`). Whitelist here rather than turning the header off. |
| `account.stylesheet` | `BILLING_ACCOUNT_STYLESHEET` | `null` | A compiled stylesheet for the standalone layout. Unused in the recommended setup, where `account.layout` points at your own layout and the package's views are added to your Tailwind source scan. |

## `config/license.php`

| Key | Env | Default | What it does |
| --- | --- | --- | --- |
| `license.tiers` | — | `[]` | What each tier **unlocks**, keyed by tier key: `features` (boolean grants) and `limits` (numeric ceilings, where `null` means uncapped). A tier, feature or limit that is not listed is denied or uncapped by the safe defaults — never an error. |

This file is licensing, `billing.php` is pricing, and they are orthogonal on purpose. Billing code never
reads `license.*` — an architecture test enforces it — and the single bridge is the `License` contract.
Neither ever blocks a public or marketing surface.

---

[← Back to the documentation index](../README.md)
