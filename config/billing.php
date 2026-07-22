<?php

declare(strict_types=1);

// Billing ≠ licensing. This file is BILLING — your end customers' subscriptions, invoices and payments.
// The separate config/license.php is LICENSING — the tiers/entitlements that govern what an owner may DO in
// the app. They are orthogonal on purpose: neither ever blocks a public/marketing surface, and billing code
// never reads config('license.*') (an arch guard enforces that) — the single sanctioned bridge is the
// License contract's ConfigLicense binding. Keep pricing here; keep entitlements in license.php.

return [

    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    |
    | When disabled, the BillingManager resolves the NullDriver — a clean no-op
    | facade so a clone without billing boots and schedules without errors. The
    | account routes and CSP gating key off this too, so a disabled install
    | exposes no billing surface at all.
    |
    */

    'enabled' => env('BILLING_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Default driver
    |--------------------------------------------------------------------------
    |
    | The payment driver used when none is named. Drivers register themselves
    | with the BillingManager (the Stripe driver ships today; Mollie and Adyen
    | are planned on the same contracts).
    |
    */

    'default' => env('BILLING_DRIVER', 'stripe'),

    /*
    |--------------------------------------------------------------------------
    | Stripe API version
    |--------------------------------------------------------------------------
    |
    | The dated Stripe API version every call and webhook runs against. The
    | package PINS this itself and is tested against it — it is not inherited from
    | whatever version the installed SDK happens to ship, because Stripe versions
    | the SHAPE of a webhook payload, and a routine `composer update` of the SDK
    | would otherwise move it silently under a mapper that reads raw fields.
    |
    | Leave it null to use the version the package was tested against. Override it
    | only deliberately, and re-run the live-Stripe suite against the new version
    | first — a mismatch is how a real billing event quietly stops firing.
    |
    */

    'stripe' => [
        'api_version' => env('BILLING_STRIPE_API_VERSION'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook path
    |--------------------------------------------------------------------------
    |
    | The path the provider posts webhooks to, handled by the WebhookReceiver.
    | The route carries no middleware group (no CSRF) — the driver's verifier
    | authenticates the request by signature instead.
    |
    */

    'webhook_path' => env('BILLING_WEBHOOK_PATH', 'billing/webhook'),

    /*
    |--------------------------------------------------------------------------
    | Webhook effects
    |--------------------------------------------------------------------------
    |
    | Each effect a webhook triggers (sync the plan, credit an add-on, send the
    | dunning notice) runs in its OWN queued job, so a slow or failing effect can
    | neither hold the provider's request open nor take the other effects down
    | with it. Point them at a dedicated queue to keep billing work off the queue
    | your app's other jobs share; leave it null to use the default queue.
    |
    | "tries" is how often a failing effect is retried before the job is marked
    | failed. It stays re-driveable after that: the delivery's raw payload is
    | stored, so `php artisan billing:webhooks:replay --failed` can run it again
    | long after the provider has stopped redelivering (Stripe gives up after
    | ~3 days).
    |
    */

    'webhooks' => [
        'connection' => env('BILLING_WEBHOOK_QUEUE_CONNECTION'),
        'queue' => env('BILLING_WEBHOOK_QUEUE'),
        'tries' => (int) env('BILLING_WEBHOOK_TRIES', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Hosted-checkout return URLs
    |--------------------------------------------------------------------------
    |
    | Where a hosted checkout (a subscription, a one-time add-on, a hosted plan
    | change) sends the customer back to. Leave these unset and they default to the
    | account hub's own routes — success_url to the checkout-return route (which
    | reconciles the subscription onto the local row so a paying customer is never
    | shown "Free"), cancel_url to the plan screen. Set them only to override that.
    | The provider appends its own parameters.
    |
    | promotion_codes turns on Stripe's promotion-code field at checkout (on by
    | default). portal_return_url is where the hosted billing portal returns the
    | customer; it falls back to success_url, then the subscription screen.
    |
    */

    'checkout' => [
        'success_url' => env('BILLING_CHECKOUT_SUCCESS_URL'),
        'cancel_url' => env('BILLING_CHECKOUT_CANCEL_URL'),
        'portal_return_url' => env('BILLING_PORTAL_RETURN_URL'),
        'promotion_codes' => env('BILLING_CHECKOUT_PROMOTION_CODES', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Owner model
    |--------------------------------------------------------------------------
    |
    | Who owns billing: "user" (each user is their own billing owner) or "team"
    | (the user's team owns billing and pays for seats). Resolved by the
    | BillingEntityResolver.
    |
    */

    'owner' => env('BILLING_OWNER', 'user'),

    /*
    | When owner is "team", the relation on the acting user that returns the team
    | which owns billing (resolved by the BillingEntityResolver). Ignored in
    | "user" mode.
    */

    'team_relation' => 'team',

    /*
    |--------------------------------------------------------------------------
    | Seats
    |--------------------------------------------------------------------------
    |
    | When a team owner pays per seat, the billed quantity has to track the seats
    | it actually occupies. The package does not own the membership table (auth
    | domain), so it reads seats from a relation you name and re-syncs the provider
    | whenever your membership events fire. A user-owner app ignores all of this.
    |
    | - membership_relation: the relation on the team model that returns its members.
    |   The HasSeats trait counts it for seatCount().
    | - active_status_column / active_status_value: filter the count to ACTIVE members
    |   when the relation is not already scoped to them (a pending invite is not a paid
    |   seat). Leave the column null when the relation only ever returns active members.
    | - membership_events: YOUR team join/leave/remove events. The queued
    |   SyncSeatsOnMembershipChange listener is registered on each, so a membership
    |   change re-syncs the seat count. Empty by default — nothing fires until you opt in.
    | - owner_properties: for an event that does not implement AffectsSeats, the
    |   listener reads the first of these properties that holds the team model.
    |
    */

    'seats' => [
        'membership_relation' => 'members',
        'active_status_column' => null,
        'active_status_value' => 'active',
        'membership_events' => [],
        'owner_properties' => ['team', 'owner'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Customer model
    |--------------------------------------------------------------------------
    |
    | The Eloquent model that owns a provider customer record, and the column it
    | stores the provider customer reference in (Cashier's default is stripe_id).
    | The driver's CustomerDirectory reads these to resolve a webhook's customer
    | reference back to the local owner. Leave the model null and a clone still
    | boots — but understand what stays off: with no model, NO subscription webhook
    | can find its owner, so a paying customer's plan is never synced. Set this to
    | your billable model before you take a real payment.
    |
    */

    'customer' => [
        'model' => env('BILLING_CUSTOMER_MODEL'),
        'column' => 'stripe_id',
    ],

    /*
    |--------------------------------------------------------------------------
    | Zero tier & tier column
    |--------------------------------------------------------------------------
    |
    | The fail-safe no-entitlement tier every resolver falls back to, and the
    | raw column the ColumnTierResolver reads (never the accessor).
    |
    */

    'zero_tier' => env('BILLING_ZERO_TIER', 'free'),

    'tier_column' => 'plan',

    /*
    | Tiers the webhook never flips (admin-comped, e.g. an unlimited grant). The
    | plan-sync effect skips these in both directions.
    */

    'untouchable_tiers' => [],

    /*
    |--------------------------------------------------------------------------
    | Default currency
    |--------------------------------------------------------------------------
    |
    | Used where an amount has no explicit currency (e.g. a zero dunning fee).
    |
    */

    'currency' => env('BILLING_CURRENCY', 'EUR'),

    /*
    |--------------------------------------------------------------------------
    | Tiers
    |--------------------------------------------------------------------------
    |
    | The tier catalog, keyed by tier key. The ORDER is the upgrade ranking.
    | Each tier: label, optional byok/untouchable flags, an optional
    | provider_price (the remote price id, e.g. a Stripe price) and an optional
    | price_display ({amount in minor units, currency}) + interval. A tier with
    | no price_display is not purchasable (e.g. the free tier). The client only
    | ever submits a tier KEY — the price is resolved here (anti-price-injection).
    |
    | provider_price may be a single id (one provider) OR a per-provider map, so
    | one tier config carries the right id per driver:
    |     'provider_price' => ['stripe' => 'price_...', 'mollie' => 'ord_...'],
    |
    | Example:
    | 'pro' => [
    |     'label' => 'Pro',
    |     'provider_price' => env('BILLING_PRICE_PRO'),
    |     'price_display' => ['amount' => 1900, 'currency' => 'EUR'],
    |     'interval' => 'month',
    |     'dimensions' => ['requests'],
    | ],
    |
    | Rotating a price in the provider does NOT strand your existing subscribers:
    | list the retired price ids under `legacy_prices` and a subscription still on
    | one resolves to this tier. A legacy price is read-only history — a NEW
    | subscription is always sold at `provider_price`.
    |
    | 'pro' => [
    |     'provider_price' => env('BILLING_PRICE_PRO'),  // what a NEW subscription is sold at
    |     'legacy_prices' => ['price_old_pro_2025'],     // still resolve to 'pro'
    | ],
    |
    | A tier may also bill for USAGE on top of its base fee — "19 EUR a month,
    | plus 0.50 EUR per 1 000 emails, first 10 000 included". Each entry under
    | `metered` is one such component, keyed by meter key:
    |
    | 'pro' => [
    |     'label' => 'Pro',
    |     'provider_price' => env('BILLING_PRICE_PRO'),   // the BASE fee item
    |     'price_display' => ['amount' => 1900, 'currency' => 'EUR'],
    |     'metered' => [
    |         'emails' => [
    |             'label' => 'Emails sent',
    |             'unit' => 'email',
    |             'provider_price' => env('BILLING_PRICE_EMAILS'), // a METERED price
    |             'provider_meter' => 'emails_sent',               // the meter's event name
    |             'package_size' => 1000,                          // billed per 1 000
    |             'unit_price' => ['amount' => 50, 'currency' => 'EUR'],
    |             'included' => 10000,                             // free allowance
    |             'policy' => 'fair_use',                          // what happens past it
    |             'warn_threshold' => 0.8,                         // warn at 80% of the allowance (default)
    |         ],
    |     ],
    | ],
    |
    | IMPORTANT — the allowance and the packaging must be configured on the
    | PROVIDER'S price as well (a graduated tier priced at 0 up to `included`,
    | then a package of `package_size`), because the provider is what rates the
    | usage. The values here render the gauge and let a local engine rate the
    | same usage. Nothing cross-checks the two for you — if they drift, the gauge
    | and the invoice drift apart. Usage is reported RAW: netting the allowance
    | locally as well would hand the customer twice the free units.
    |
    | For a PRICING SURFACE — the in-app upgrade grid AND a public /pricing page —
    | a tier may carry presentation-only metadata that the shared PricingCatalog
    | reads (never a view, so the two surfaces cannot drift):
    |
    | 'pro' => [
    |     'label' => 'Pro',
    |     // ... price as above ...
    |     'features'  => ['pricing.pro.projects', 'pricing.pro.priority_support'], // ordered i18n KEYS, never raw text
    |     'highlight' => true,                    // render this card emphasized
    |     'badge'     => 'pricing.badge.popular', // an optional ribbon (also an i18n key)
    | ],
    |
    | `features` is a list of translation keys — YOUR app owns the strings, in every
    | locale — which PricingCatalog::bulletsFor() resolves in order. `highlight` and
    | `badge` are optional; leaving them off simply renders a plain card.
    |
    */

    'tiers' => [
        'free' => [
            'label' => 'Free',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Metered dimensions
    |--------------------------------------------------------------------------
    |
    | Extra dimensions a CUSTOM UsageProvider reports, keyed by dimension key:
    | {label, unit, period, warn_threshold}. The default CounterUsageProvider
    | does not read this — it derives its dimensions from each tier's `metered`
    | components above, so what an owner sees on the usage screen is exactly
    | what they are billed for.
    |
    */

    'dimensions' => [],

    /*
    |--------------------------------------------------------------------------
    | Metering
    |--------------------------------------------------------------------------
    |
    | How recorded usage is handed to the provider that bills it. The flush runs
    | every minute (billing:usage:flush) and retries with exponential backoff, so
    | a provider outage delays billing rather than losing it.
    |
    | max_attempts is a deadline, not a limit: past it the usage is marked failed
    | and logged as an error, because it is revenue that will not be collected
    | unless someone acts. Do not raise it to hide a persistent failure.
    |
    | stall_hours is the other deadline: how long usage may sit unreported in the
    | outbox before billing:usage:reconcile calls it a stall (a UsageBacklogStalled
    | event) rather than a passing outage. Set it under your provider's back-dated
    | acceptance window — past that window the usage cannot be billed at all.
    |
    */

    'metering' => [
        'max_attempts' => 8,
        'backoff_seconds' => 60,
        'stall_hours' => 6,
    ],

    /*
    |--------------------------------------------------------------------------
    | Usage reservations
    |--------------------------------------------------------------------------
    |
    | How long a hold on a metered allowance stands before it is handed back.
    | UsageRecorder::meter() claims the allowance BEFORE the work runs, so a
    | hard limit cannot be oversold by requests firing in parallel — but a worker
    | killed between claiming and recording would hold that allowance forever, and
    | the owner would be refused requests they never spent. Every hold therefore
    | expires, and `billing:usage:flush` (scheduled every minute) reclaims it.
    |
    | Set it comfortably longer than your slowest metered request, and shorter
    | than you would tolerate an owner being short of allowance they did not use.
    |
    */

    'usage' => [
        'hold_seconds' => (int) env('BILLING_USAGE_HOLD_SECONDS', 900),
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit ledger
    |--------------------------------------------------------------------------
    |
    | What the billing audit ledger records. 'money' (the default) writes every
    | money movement and entitlement/state change — comps, refunds, credits,
    | dunning, plan grants and revokes, in-app cancels and swaps, erasure — the
    | events an auditor, or a "why is this customer on free?" question, needs. It
    | is always on and never noisy.
    |
    | 'all' additionally records the high-volume, navigational and read-side
    | events (a customer opening checkout or adding a card). Turn it on when you
    | want a complete trail and can carry the volume.
    |
    */

    'audit' => [
        'level' => env('BILLING_AUDIT_LEVEL', 'money'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Erasure & retention (personal data)
    |--------------------------------------------------------------------------
    |
    | `billing:erase {owner}` answers a right-to-erasure request. It purges the
    | owner's operational rows and their stored provider API keys, and scrubs the
    | personal data out of the webhook payloads the package kept.
    |
    | It deliberately does NOT delete their invoices. A valid invoice has to carry
    | the buyer's name and address (§14 UStG) and has to be kept for years (§147 AO,
    | §14b UStG) — the right to erasure yields to a legal retention obligation
    | (GDPR Art. 17(3)(b)). Those rows are unlinked from the owner and kept, and
    | `billing:prune` removes them once the retention window closes. Check the
    | window against your own jurisdiction: the default is the German one.
    |
    | forget_customer additionally DELETES the customer at the provider. That is
    | irreversible and it cancels their live subscriptions there, so it is off by
    | default — turn it on deliberately. The provider keeps its own invoice and
    | charge records regardless.
    |
    */

    'erasure' => [
        'forget_customer' => env('BILLING_ERASURE_FORGET_CUSTOMER', false),
    ],

    'retention' => [
        // Long past the provider's own redelivery window (Stripe gives up after ~3 days), which is the
        // only reason the payload is kept at all.
        'webhook_payload_days' => (int) env('BILLING_RETENTION_WEBHOOK_PAYLOAD_DAYS', 90),

        // The financial records of erased owners: ~10 years (§147 AO / §14b UStG).
        'erased_financial_days' => (int) env('BILLING_RETENTION_ERASED_FINANCIAL_DAYS', 3650),

        // The audit ledger. GDPR storage limitation vs. bookkeeping retention (§257 HGB / §147 AO): the
        // default is the longer, book-keeping window. Check it against your own obligations.
        'audit_days' => (int) env('BILLING_RETENTION_AUDIT_DAYS', 3650),
    ],

    /*
    |--------------------------------------------------------------------------
    | Quota
    |--------------------------------------------------------------------------
    |
    | The metered-quota gate. Apply it per route with the `billing.quota:<meter>`
    | middleware (optionally `billing.quota:<meter>,<units>`) to refuse a request
    | that would take the owner past a BLOCKING allowance (a hard_stop / refuse
    | meter). A degrade or fair-use meter is never blocked by the gate. `status` is
    | the HTTP code a blocked request aborts with.
    |
    */

    'quota' => [
        'status' => 429,
    ],

    /*
    |--------------------------------------------------------------------------
    | Cards
    |--------------------------------------------------------------------------
    |
    | The proactive card-expiry warner (billing:cards:warn, scheduled daily) nudges
    | an owner whose default card expires within this many days — the biggest
    | preventable cause of involuntary churn. Override per run with --days.
    |
    */

    'cards' => [
        'warn_within_days' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Add-ons
    |--------------------------------------------------------------------------
    |
    | One-time purchasable add-ons, keyed by add-on key: {label, provider_price,
    | price_display}. Like tiers, the client submits the add-on KEY, never a price.
    |
    | An add-on grants EITHER money credit (the default — it lands on the owner's
    | balance and reduces their next invoice) OR usage UNITS of a meter:
    |
    |   'extra_emails' => [
    |       'label' => 'Extra emails',
    |       'provider_price' => 'price_...',
    |       'price_display' => ['amount' => 3000, 'currency' => 'EUR'],
    |       'grants' => ['meter' => 'emails', 'units' => 1000],
    |   ],
    |
    | Granted units are PREPAID: they never expire (the tier's per-cycle `included`
    | allowance does), and usage spends the free allowance FIRST and only then the
    | units the owner paid for. Prepaid-covered usage is netted out before the
    | provider is told about it, so the customer is never billed for units they
    | already bought. A refund claws back the units they have NOT used yet.
    |
    */

    'addons' => [],

    /*
    |--------------------------------------------------------------------------
    | Dunning ladder
    |--------------------------------------------------------------------------
    |
    | The multi-level dunning ladder, in order. Each rung: {after_days, optional
    | fee {amount, currency}, optional label}. The delinquency clock is a
    | timestamp, never a gateway status.
    |
    | `billing:dunning:advance` (scheduled daily) walks this ladder: each run sends
    | the next rung's suspension warning once its after_days is reached, and charges
    | its `fee` if one is set (added to the owner's next invoice). Add a fee to a
    | rung to charge it:
    |
    |     ['after_days' => 14, 'label' => 'Final notice',
    |      'fee' => ['amount' => 500, 'currency' => 'EUR']],
    |
    */

    'dunning' => [
        ['after_days' => 3, 'label' => 'First reminder'],
        ['after_days' => 7, 'label' => 'Second reminder'],
        ['after_days' => 14, 'label' => 'Final notice'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Dunning block status
    |--------------------------------------------------------------------------
    |
    | The HTTP status the `billing.dunning` middleware returns to a NON-browser
    | request (API / JSON) from an owner whose payment has failed. A browser
    | request is redirected to the payment-recovery screen instead; this status
    | is what an API client sees. 402 Payment Required is the natural default.
    |
    */

    'dunning_status' => 402,

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | Where a billing notice goes. Mail by default. An app that keeps an in-app
    | feed adds 'database' — every billing notification already carries a
    | toArray() payload, so the database channel works the moment you switch it
    | on (run Laravel's own notifications migration first).
    |
    | This chooses the TRANSPORT only, never whether the customer is told. Billing
    | notices are transactional and non-suppressible: a preference screen must not
    | offer to switch off "your payment failed". An empty/unusable value here falls
    | back to mail rather than sending nothing.
    |
    */

    'notifications' => [
        'channels' => ['mail'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Realtime
    |--------------------------------------------------------------------------
    |
    | Opt-in live refresh for the account-hub screens. OFF by default: the billing
    | events only broadcast when this is on AND a broadcaster (e.g. Reverb) is
    | configured, so a plain install (or a native app) has nothing on the wire and
    | falls back to a bounded poll. Switch it on once your Echo/Reverb is wired.
    |
    */

    'realtime' => [
        'enabled' => env('BILLING_REALTIME', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Coupons
    |--------------------------------------------------------------------------
    |
    | Package-owned discount codes, keyed by the exact code the customer enters.
    | Each is EITHER a percentage off (`percent`, 1..100) OR a fixed amount off
    | (`amount` in minor units + `currency`), with an optional `expires_at` date
    | after which the code stops resolving. The package RESOLVES a code to a
    | Discount (DiscountResolver) — the neutral model the local engine's invoice
    | math will apply.
    |
    | For the Stripe driver, add `stripe_coupon` (a Stripe coupon or promotion-code
    | id): when a visitor enters the code at checkout it is passed through as a
    | Checkout Session discount, and Stripe owns the money math plus its native
    | max_redemptions / redeem_by. A code without `stripe_coupon` still resolves
    | (and validates in the UI) but applies no discount at Stripe.
    |
    | Example:
    | 'coupons' => [
    |     'WELCOME10' => ['percent' => 10, 'stripe_coupon' => 'coupon_welcome10'],
    |     'LAUNCH5'   => ['amount' => 500, 'currency' => 'EUR', 'expires_at' => '2026-12-31'],
    | ],
    |
    */

    'coupons' => [],

    /*
    |--------------------------------------------------------------------------
    | Runtime
    |--------------------------------------------------------------------------
    |
    | Where the account hub renders: "web" (a browser) or "native" (a mobile app
    | webview). Navigation items flagged web_only are hidden on a native runtime —
    | for flows an app store forbids from being completed in-app (e.g. account
    | deletion or a link out to an external billing portal).
    |
    */

    'runtime' => env('BILLING_RUNTIME', 'web'),

    /*
    |--------------------------------------------------------------------------
    | External billing link-out (No-/external-Merchant-of-Record)
    |--------------------------------------------------------------------------
    |
    | For a mode where an EXTERNAL merchant of record owns billing (an app store's
    | subscription management, an external Lane/Fuel portal), set this to that
    | portal's URL. The account hub then links OUT to it for billing management
    | instead of offering in-app checkout it is not the merchant of record for.
    | The value is scheme-restricted (only absolute http/https with a host passes,
    | via SafeExternalUrl); anything else is ignored and link-out stays off.
    |
    */

    'link_out' => env('BILLING_LINK_OUT'),

    /*
    |--------------------------------------------------------------------------
    | Admin console
    |--------------------------------------------------------------------------
    |
    | The optional publishable admin console (billing metrics, the audit log, and a
    | comp-a-tier action) mounts under `prefix` behind `middleware`, and every access
    | is authorized against the `ability` Gate — which YOUR app defines. Until you
    | define it, the Gate denies everyone (fail-closed), so the console is never open
    | by accident. It renders only when Livewire is installed.
    |
    */

    'admin' => [
        'ability' => env('BILLING_ADMIN_ABILITY', 'billing-admin'),
        'prefix' => env('BILLING_ADMIN_PREFIX', 'admin/billing'),
        'middleware' => ['web', 'auth'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Account-hub navigation
    |--------------------------------------------------------------------------
    |
    | The account-hub nav items, keyed by item key. Each needs a label (an i18n
    | key or literal) and a route; icon and order are optional (items sort by
    | order, ties keeping config order). Malformed items are dropped. The screens
    | render whatever is listed here, so a consumer can add, reorder or remove
    | sections without touching the package.
    |
    | The default below is the full hub. Reorder, relabel or remove any of it — the
    | route names are the ones the package registers (billing.account.{overview,
    | subscription,plan,invoices,payment-methods,usage,recovery,danger,portal}).
    |
    */

    'navigation' => [
        'subscription' => ['label' => 'billing::account.nav.subscription', 'route' => 'billing.account.subscription', 'group' => 'subscription', 'order' => 10],
        'plan' => ['label' => 'billing::account.nav.plan', 'route' => 'billing.account.plan', 'group' => 'subscription', 'order' => 20],
        'payment-methods' => ['label' => 'billing::account.nav.payment_methods', 'route' => 'billing.account.payment-methods', 'group' => 'billing', 'order' => 30],
        'invoices' => ['label' => 'billing::account.nav.invoices', 'route' => 'billing.account.invoices', 'group' => 'billing', 'order' => 40],
        'usage' => ['label' => 'billing::account.nav.usage', 'route' => 'billing.account.usage', 'group' => 'usage', 'order' => 50],
        'usage-history' => ['label' => 'billing::account.nav.usage_history', 'route' => 'billing.account.usage-history', 'group' => 'usage', 'order' => 55],
        'recovery' => ['label' => 'billing::account.nav.recovery', 'route' => 'billing.account.recovery', 'group' => 'billing', 'order' => 60],
        'danger' => ['label' => 'billing::account.nav.danger', 'route' => 'billing.account.danger', 'group' => 'account', 'web_only' => true, 'order' => 70],
    ],

    /*
    |--------------------------------------------------------------------------
    | Suspension ladder
    |--------------------------------------------------------------------------
    |
    | Per-surface lockout thresholds, keyed by surface name. The value is the
    | dunning-level position at which that surface locks — the surface is locked
    | once the owner reaches that level or higher, so different surfaces can be
    | withdrawn at different stages of delinquency (e.g. lock the API early, the
    | dashboard last). A surface with no threshold never locks.
    |
    | Example:
    | 'suspension' => [
    |     'api'       => 2,
    |     'dashboard' => 3,
    | ],
    |
    */

    'suspension' => [],

    /*
    |--------------------------------------------------------------------------
    | Tax
    |--------------------------------------------------------------------------
    |
    | How tax is computed: "provider" (defer to a provider that supports it),
    | "eu_oss" (the static EU-OSS VAT table) or "none" (never add tax). Tax is a
    | driver-capability decision, not a checkout option the neutral layer sets.
    |
    */

    'tax' => env('BILLING_TAX', 'none'),

    /*
    |--------------------------------------------------------------------------
    | Trial
    |--------------------------------------------------------------------------
    |
    | The trial policy — length, kind and whether a card is required — resolved by the TrialPolicy. The
    | package default is NO trial; a project opts in here (or per tier under tiers.<key>.trial). Every
    | knob is project-overridable, so nothing hardcodes a trial rule.
    |
    */

    'trial' => [
        // The trial length in days. 0 disables trials. Per-tier override: tiers.<key>.trial.days.
        // Cast: env() hands back a STRING, and the resolver treats a non-numeric length as no trial.
        'days' => (int) env('BILLING_TRIAL_DAYS', 0),

        // Which kind of trial to grant: 'none', 'subscription' (part of the subscription, collected at
        // checkout via Stripe trial_period_days) or 'generic' (no subscription — granted by Trials::grant()
        // and unlocking generic_tier). Null derives it: a configured generic_tier implies 'generic',
        // otherwise a positive length implies 'subscription'. Per-tier override: tiers.<key>.trial.mode.
        'mode' => env('BILLING_TRIAL_MODE'),

        // The tier a GENERIC trial unlocks (a trial with no subscription — "try Pro for 14 days, no card").
        // Null disables generic trials: without a tier to unlock there is nothing to grant.
        'generic_tier' => env('BILLING_TRIAL_GENERIC_TIER'),

        // Whether a SUBSCRIPTION trial requires a card up front. true collects a payment method at checkout
        // (the charge lands automatically when the trial ends); false lets the owner trial without one
        // (Stripe collects the card only if the trial converts). Per-tier override:
        // tiers.<key>.trial.requires_payment_method.
        'requires_payment_method' => env('BILLING_TRIAL_REQUIRES_PM', true),

        // How many days before a trial ends the app-shell banner starts nudging the owner to pick a plan.
        'ending_within_days' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Company (invoice seller)
    |--------------------------------------------------------------------------
    |
    | Your own details as they appear on invoices — the seller party on an
    | e-invoice (EN 16931 / XRechnung). Fill these in to emit compliant electronic
    | invoices; the seller is the platform itself, so it is configured once here
    | rather than stored per invoice.
    |
    */

    'company' => [
        'name' => env('BILLING_COMPANY_NAME'),
        'vat_id' => env('BILLING_COMPANY_VAT_ID'),
        'address' => env('BILLING_COMPANY_ADDRESS'),
        'postcode' => env('BILLING_COMPANY_POSTCODE'),
        'city' => env('BILLING_COMPANY_CITY'),
        'country' => env('BILLING_COMPANY_COUNTRY', 'DE'),
        // The seller electronic address (EN 16931 BT-34) + its scheme (EAS code; "EM" = email).
        // XRechnung 3.0 makes BT-34 MANDATORY, so an endpoint must always resolve: set this explicitly,
        // or the renderer falls back to a company email ("EM") if configured, else the vat_id above
        // (EAS "9930"). Configure at least one of endpoint_id / email / vat_id, or the e-invoice is rejected.
        'endpoint_id' => env('BILLING_COMPANY_ENDPOINT_ID'),
        'endpoint_scheme' => env('BILLING_COMPANY_ENDPOINT_SCHEME', 'EM'),
    ],

    /*
    |--------------------------------------------------------------------------
    | DATEV export
    |--------------------------------------------------------------------------
    |
    | Numbers for the DATEV "Buchungsstapel" (EXTF) export. These are specific to
    | your chart of accounts and your tax advisor's DATEV setup — confirm the
    | revenue/receivables accounts, the account length and any BU tax key with your
    | Steuerberater before importing. Left empty, the export still produces a
    | structurally-valid file with blank account fields to fill in.
    |
    */

    'datev' => [
        'consultant' => env('BILLING_DATEV_CONSULTANT'),
        'client' => env('BILLING_DATEV_CLIENT'),
        'account_length' => (int) env('BILLING_DATEV_ACCOUNT_LENGTH', 4),
        'revenue_account' => env('BILLING_DATEV_REVENUE_ACCOUNT'),
        'customer_account' => env('BILLING_DATEV_CUSTOMER_ACCOUNT'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Marketplace (multi-merchant)
    |--------------------------------------------------------------------------
    |
    | The optional multi-merchant marketplace surface (Stripe Connect). OFF by
    | default: with `enabled` false the whole marketplace path is unreachable and
    | the single-merchant behavior is byte-identical.
    |
    | `seller_of_record` names WHO the seller is to the buyer, per sale — a
    | liability/VAT decision the package never makes for you, only enforces. It
    | turns on what is sold: an electronically-supplied service falls under the
    | Art. 9a deemed-supplier presumption (the platform is the seller by law;
    | Art. 9a VAT-IR (EU) 282/2011, CJEU C-695/20), physical goods do not. Pick a
    | default, whitelist the postures you have opted into, and — only for a genuine
    | non-electronic / hands-off case — assert the Art. 9a rebuttal to unlock the
    | `seller_of_record` posture. See the posture guide before changing these.
    |
    */

    'marketplace' => [
        'enabled' => (bool) env('BILLING_MARKETPLACE_ENABLED', false),

        'seller_of_record' => [
            // platform_deemed_supplier | seller_of_record | platform_intermediary
            'default_posture' => env('BILLING_MARKETPLACE_POSTURE', 'platform_deemed_supplier'),

            // The postures you have deliberately opted into. Resolving one outside this list is refused.
            'allowed_postures' => ['platform_deemed_supplier'],

            // Default classification of what is sold. true = electronically-supplied service (Art. 9a
            // applies); false = physical goods. Override per product class through the resolver.
            'supplies_are_electronic' => (bool) env('BILLING_MARKETPLACE_SUPPLIES_ELECTRONIC', true),

            // The Art. 9a rebuttal: `seller_of_record` for an electronic supply is refused unless ALL four are
            // true. A platform that sets its own terms, authorizes billing or approves the supply cannot
            // truthfully assert these — leave them false and stay the deemed supplier.
            'art9a_rebuttal_asserted' => (bool) env('BILLING_MARKETPLACE_ART9A_REBUTTAL', false),
            'no_agb_control' => false,
            'no_billing_authorization' => false,
            'no_supply_authorization' => false,
        ],
    ],

];
