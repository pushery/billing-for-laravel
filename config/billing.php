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
    | with the BillingManager (the Stripe driver ships today; other drivers
    | register on the same contracts).
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
    | Mollie
    |--------------------------------------------------------------------------
    |
    | Only read when `default` is `mollie`. The key is required there and the
    | driver refuses to build a client without one — an install that selects the
    | driver and forgets the key does not fail at boot, it fails at the first
    | charge, inside a scheduled run, against a real subscriber.
    |
    | A blank value counts as missing on purpose: a set-but-empty variable is
    | what a half-finished deployment leaves behind, and it reads as configured
    | to anybody looking at the file.
    |
    */

    'mollie' => [
        'api_key' => env('BILLING_MOLLIE_API_KEY'),

        // Where Mollie sends the customer back and posts its status pings. It must be ABSOLUTE and
        // reachable from the internet, which is why it is configuration rather than a generated route URL:
        // a package cannot know the public host, and a URL generated from a CLI run — which is exactly
        // where the scheduled billing run creates payments — has no request to take the host from.
        //
        // Left null it falls back to the app URL joined with `billing.webhook_path`. That is right for a
        // single-host install and wrong for anything behind a tunnel in development, where the value has to
        // be the tunnel's, or the webhook is posted to a host Mollie cannot reach.
        'webhook_url' => env('BILLING_MOLLIE_WEBHOOK_URL'),

        // The payment methods this Mollie account offers at checkout. Configurable because Mollie enables
        // methods PER ACCOUNT — a fixed list would be wrong for most installs in both directions at once,
        // offering something the account cannot take and hiding something it can.
        //
        // Left null, the driver falls back to the methods a mandate can exist for. Not an empty list:
        // empty reads as "this account can take no payments", and every screen that asks would render
        // nothing at all.
        'methods' => null,

        // The signing secret for Mollie's next-generation webhooks, which carry an HMAC-SHA256 in
        // `X-Mollie-Signature`. Accepts a LIST as well as a single string: rotation is a period where both
        // secrets are live, and without that an operator has to choose between rotating and losing
        // webhooks — which is not a choice, it is a reason not to rotate.
        //
        // Write the list COMMA-SEPARATED, which is what this line can carry and what Mollie's own package
        // documents: BILLING_MOLLIE_WEBHOOK_SECRET=old_secret,new_secret. Spacing and empty entries are
        // ignored, so a trailing comma costs nothing. Both are accepted for as long as both are set;
        // delete the old one when the dashboard no longer signs with it.
        //
        // Left null, the driver takes the legacy path: the ping is unsigned and the authentication is the
        // fetch the mapper does, since an attacker cannot invent a status Mollie will confirm. That
        // fallback is not optional — every install still on legacy webhooks would otherwise start refusing
        // every ping on the day it updated.
        //
        // Set it, and an UNSIGNED ping is refused: you have said your account signs, so an unsigned
        // request is either a misconfiguration or somebody knocking.
        'webhook_secret' => env('BILLING_MOLLIE_WEBHOOK_SECRET'),
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

    /*
    |--------------------------------------------------------------------------
    | Starting a subscription with a local-engine driver
    |--------------------------------------------------------------------------
    |
    | A provider with no synchronous setup call establishes the mandate through a
    | first payment the customer completes on the provider's own page.
    |
    | subscribe_return_url is where they come back to. It falls back to
    | checkout.success_url, and an install that set neither is refused rather than
    | redirected to nowhere: the customer would complete a real payment and land on
    | an error page holding a mandate nothing told them about.
    |
    | mandate_verification_minor is what that first payment charges, in minor units
    | of the plan's currency. It appears on the customer's statement, which is why
    | it is configurable — and it is the SMALLEST unit by default rather than the
    | plan price, because its purpose is to create a mandate. The first cycle is
    | billed by the engine on its own schedule, and collecting it here as well
    | would charge the customer twice for one period.
    |
    */

    'subscribe_return_url' => env('BILLING_SUBSCRIBE_RETURN_URL'),

    'mandate_verification_minor' => env('BILLING_MANDATE_VERIFICATION_MINOR', 1),

    'checkout' => [
        'success_url' => env('BILLING_CHECKOUT_SUCCESS_URL'),
        'cancel_url' => env('BILLING_CHECKOUT_CANCEL_URL'),
        'portal_return_url' => env('BILLING_PORTAL_RETURN_URL'),
        // Where a hosted "add a card" page returns the customer. `CheckoutUrls::paymentMethodsReturnUrl()`
        // has read this since it was written; it was simply never declared here, so the one key of the four
        // that an adopter could not discover by reading the published config was the one they were most
        // likely to need. Absent it still falls back to the payment-methods route, exactly as before.
        'payment_methods_return_url' => env('BILLING_PAYMENT_METHODS_RETURN_URL'),
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
    |--------------------------------------------------------------------------
    | Subscription swap timing
    |--------------------------------------------------------------------------
    |
    | When a downgrade takes effect. An upgrade is always immediate (the customer
    | asked for more and pays the prorated difference). A downgrade defaults to
    | 'period_end' — the current cycle is already paid at the higher tier, so
    | moving down mid-cycle would owe a refund or take away paid-for access.
    | Set to 'immediate' to downgrade at once instead. Both the screen and the
    | swap read this one value, so they cannot disagree about when a change lands.
    |
    */

    'subscriptions' => [
        'downgrade_timing' => env('BILLING_DOWNGRADE_TIMING', 'period_end'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Order-item preprocessors
    |--------------------------------------------------------------------------
    |
    | Steps that may reshape a billing cycle's lines before its order is written,
    | run in the order listed here. Each implements OrderItemPreprocessor and is
    | resolved through the container, so a step may declare its own dependencies.
    |
    | This is empty by default and that is the correct default: the local engine
    | bills the flat plan price, and what a cycle costs BEYOND that — metered
    | consumption, an application's own arithmetic — is a question only the
    | consuming application can answer. A driver that prices remotely (Stripe,
    | through meters) never reaches this at all.
    |
    | The order matters. A step that prices usage and a step that applies a
    | percentage discount give different answers depending on which runs first,
    | and this list is the only honest place for that decision.
    |
    | A step that throws aborts the cycle before it is claimed. That is
    | deliberate: a half-priced order would be charged against a total nothing
    | reproduces, and the claim would stop the next run from ever revisiting it.
    |
    */

    'order_item_preprocessors' => [],

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
    |     'provider_price' => ['stripe' => 'price_...'],
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

        // The INVOICE window: an erased owner's retained invoices are kept eight years (2920 days) — §14b
        // Abs. 1 UStG n. F. The clock is counted from the END of the year the invoice was issued (§147 Abs. 4
        // AO), which billing:prune anchors to; a shorter window refuses to boot. This is a FLOOR and a
        // default: set your own for another jurisdiction. Deliberately NOT the same as audit_days below —
        // over-retaining an invoice to the ten-year book window keeps personal data two years past its
        // obligation, in breach of storage limitation (Art. 5(1)(e)).
        'erased_financial_days' => (int) env('BILLING_RETENTION_ERASED_FINANCIAL_DAYS', 2920),

        // The BOOK/BATCH window for the audit ledger: ten years (§257 HGB / §147 AO) — longer than the
        // invoice window above ON PURPOSE. The two numbers (3650 vs 2920) are different windows for different
        // record classes, not a value that got out of sync; do not "unify" them.
        'audit_days' => (int) env('BILLING_RETENTION_AUDIT_DAYS', 3650),

        // How long the evidence for a sale's country is kept. Deliberately LONGER than the document window
        // and deliberately its own key: the two come from different obligations, and merging them would
        // prune the evidence years before the return it justifies stops being examinable — leaving a filed
        // figure with nothing behind it.
        'place_evidence_days' => (int) env('BILLING_RETENTION_PLACE_EVIDENCE_DAYS', 3650),

        // The escape hatch for a jurisdiction whose invoice minimum genuinely is SHORTER than the German
        // floor above. Left false, a shorter erased_financial_days refuses to boot rather than prune tax
        // records early. It is declared here rather than left as an undocumented read, because a fail-closed
        // guard whose opt-out appears in no published file is one a consumer can only discover by hitting it.
        'allow_below_statutory_minimum' => (bool) env('BILLING_RETENTION_ALLOW_BELOW_STATUTORY_MINIMUM', false),
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
    | An optional `archetype` says WHAT KIND of thing the add-on is, using one of
    | the nine product archetypes. It is what lets the package answer the questions
    | that depend on the kind rather than the price -- and the one it answers today
    | is the consumer-withdrawal right, which decides whether provision may begin
    | before the buyer has confirmed anything:
    |
    |   'novel' => [
    |       'label' => 'A novel',
    |       'provider_price' => 'price_...',
    |       'archetype' => 'download',
    |   ],
    |
    | Optional, and absent means UNCLASSIFIED rather than a default -- a guessed
    | archetype is a guessed tax treatment and a guessed withdrawal right, and both
    | are wrong quietly. With no consumer-rights profile set, nothing reads it. A
    | value that is not one of the archetypes is refused rather than resolved to
    | null, because a typo is not the same thing as "nobody classified this".
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
    | Cure window
    |--------------------------------------------------------------------------
    |
    | How many days a subscription in arrears may still be rescued before it
    | expires for good. A daily reminder goes out on each day of this window.
    |
    | This is a SIBLING of the ladder above, not a rung of it, because the two
    | answer different questions. The ladder decides which surfaces a delinquent
    | customer loses as time passes; this decides how long the relationship
    | itself survives.
    |
    | It applies to MARKETPLACE subscriptions only. The window exists because a
    | customer holds several subscriptions to several merchants and loses only
    | the ones in arrears; a single-seller install keeps the ladder, where
    | nothing is ever canceled. The sweeps therefore select rows that came
    | through the marketplace rather than installs that have the flag on — a
    | single-seller install has no such row, so it stays byte-identical whatever
    | the flag does, and switching the flag on never pulls the platform's own
    | subscriptions into a rule they were not sold under.
    |
    | Floors at one day: a window of zero would put the reminder and the expiry
    | on the same day, which tells the customer nothing they can act on.
    |
    */

    'dunning_cure_window_days' => (int) env('BILLING_DUNNING_CURE_WINDOW_DAYS', 7),

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

        /*
         * Whether this package renders somewhere for those toasts to LAND.
         *
         * OFF by default, and that default is the whole decision. The bridge dispatches a `wirekit-toast`
         * browser event; the consumer for whom that already works is a WireKit host, whose own toast region
         * reads exactly this event. Shipping a second region on by default would show that consumer every
         * toast twice — a regression visible only in a browser.
         *
         * Turn it on if your application has no toast region of its own. You then get a minimal one: two
         * `aria-live` containers (polite for info and success, assertive for warning and danger) and a small
         * inline listener that appends the message and dismisses it after a few seconds. It brings no UI kit
         * and no build step.
         *
         * The third option is neither: leave this off and write your own one-line listener on
         * `wirekit-toast`, reading `detail.message` and `detail.variant`.
         */
        'render_toast_region' => env('BILLING_REALTIME_TOAST_REGION', false),
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
    | Tax rate matrix
    |--------------------------------------------------------------------------
    |
    | Rates keyed by destination country AND supply category, for jurisdictions
    | that tax some supplies at a reduced rate. Null (the default) means the
    | built-in standard-rate table answers on its own, exactly as before.
    |
    | This is a SIBLING key, deliberately. "tax" above is a scalar that selects
    | the calculator; turning it into an array to hold sub-keys would make it
    | match no mode, and an unresolvable mode is refused at boot — so the whole
    | application would stop rather than quietly charge nothing. Keeping the new
    | shape next to it leaves the old key's type and value range untouched.
    |
    | Rates are basis points (1900 = 19%), like every other rate in this package.
    | "valid_from" is the day the table was known to be correct; the doctor
    | command reports how old it is, because a rate table with no date goes on
    | answering with the confidence of the day it was written.
    |
    | Example:
    | 'tax_matrix' => [
    |     'valid_from' => '2026-01-01',
    |     'max_age_days' => 180,
    |     'rates' => [
    |         'DE' => ['standard' => 1900, 'reduced' => 700],
    |         'AT' => ['standard' => 2000, 'reduced' => 1000],
    |     ],
    | ],
    |
    | 'max_age_days' is how old this table may get before `billing:doctor` calls it
    | out, and it defaults to 180. It lives inside the matrix rather than beside it
    | because the answer depends on the table: a hand-maintained one for two
    | countries can sit longer than a broad one, and the person who wrote the table
    | is the person who knows which.
    |
    | An optional 'history' holds the SAME rates as dated intervals, for documents
    | written today about a supply taxed earlier. The law binds the rate to the tax
    | point rather than to the moment of lookup, so a late invoice, a re-billing or
    | a migration of historic sales must be able to ask for the rate that applied
    | THEN. Without a history that question cannot be asked and every such document
    | takes today's rate:
    |
    | 'history' => [
    |     [
    |         'valid_from' => '2024-01-01',
    |         'valid_to' => '2025-07-01',
    |         'source' => 'Estonian Tax and Customs Board',
    |         'source_version' => '2024-01',
    |         'fetched_at' => '2024-01-02',
    |         'approved_by' => null,
    |         'rates' => ['EE' => ['standard' => 2200, 'reduced' => 900]],
    |     ],
    |     [
    |         'valid_from' => '2025-07-01',
    |         'valid_to' => null,
    |         'source' => 'Estonian Tax and Customs Board',
    |         'source_version' => '2025-07',
    |         'fetched_at' => '2025-07-01',
    |         'rates' => ['EE' => ['standard' => 2400, 'reduced' => 900]],
    |     ],
    | ],
    |
    | Absent, nothing changes: a tax point traveling on the money path is simply
    | ignored and every sale prices exactly as it does today. Present, a country
    | the history CARRIES is answered from it, and a tax point falling in a gap is
    | REFUSED rather than answered with the nearest rate — a made-up rate with a
    | date on it cannot be told apart from a real one. A country the history says
    | nothing about keeps being priced by the table above, so opting in for one
    | member state does not turn every other into an unknown.
    |
    | Intervals are append-only: an overlapping pair is refused at boot, because
    | one supply cannot have two rates and picking the first match would decide
    | that silently. A rate change appends an interval and closes the previous one.
    |
    */

    'tax_matrix' => null,

    /*
    |--------------------------------------------------------------------------
    | Cross-border consumer sales
    |--------------------------------------------------------------------------
    |
    | Whether the small-turnover threshold applies to you. "threshold_waived"
    | defaults to TRUE, and that does NOT mean this package declared anything on
    | your behalf — it means no origin-country fallback is applied, which is the
    | direction that never under-charges. An operator who wants the threshold
    | watched turns it off and binds a CrossBorderSalesCounter.
    |
    | Turning it off inside the binding period of a declaration you actually made
    | is refused at boot: it would contradict a filing the revenue office holds,
    | and silently reverting to origin taxation would under-declare in every
    | destination country until somebody noticed. Record when you declared it in
    | "waived_since" so that guard can see it.
    |
    | "warning_levels" are fractions of the threshold at which you want to hear
    | about it, so a registration does not begin on the day it is already needed.
    |
    | This is a SIBLING of "tax" above, never a child — that key is a scalar, and
    | an array there resolves to no tax mode at all.
    |
    */

    'tax_oss' => [
        'threshold_waived' => (bool) env('BILLING_TAX_OSS_THRESHOLD_WAIVED', true),
        'waived_since' => env('BILLING_TAX_OSS_WAIVED_SINCE'),

        // How many years a declaration that gave up the threshold binds for. The operator's tax authority
        // sets this term, not the package — it is checked at boot, because discovered at the next sale the
        // fallback is a quiet one and every document issued meanwhile reads as normal while being wrong.
        'binding_years' => (int) env('BILLING_TAX_OSS_BINDING_YEARS', 2),

        // `required_signals` LIVED HERE AND DECIDED NOTHING. The evidence standard is
        // `billing.tax_evidence.required_signals`, which is what the policy gates the sale on; this key was
        // read by exactly one place — the evidence record's own stamp — so setting it changed what a record
        // CLAIMED without changing what was actually required. Both defaulted to 2, which is why nothing
        // looked wrong until somebody configured.
        //
        // Removed rather than aliased: an alias would keep two spellings alive for one idea, and the idea is
        // the thing that was duplicated.
        'warning_levels' => [0.80, 0.95],

        // How long a jurisdiction lets a filed period be corrected. Measured from the DUE date, not the
        // period end — those are a month apart, and measuring from the wrong one lets through a correction
        // that is already out of time. Past the window the export REFUSES rather than dropping the line: a
        // correction that vanishes is indistinguishable from one that was never owed.
        'correction_window_years' => (int) env('BILLING_TAX_OSS_CORRECTION_WINDOW_YEARS', 3),

        // Where a produced return file is put. Null writes nowhere — the record of what was produced is kept
        // either way. The package files nothing with anybody and knows no portal credentials; it hands the
        // operator's accounting a file to check.
        'export_disk' => env('BILLING_TAX_OSS_EXPORT_DISK'),
        'export_path' => env('BILLING_TAX_OSS_EXPORT_PATH', 'tax-returns'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Jurisdiction profile
    |--------------------------------------------------------------------------
    |
    | Which country's obligations the package enforces where it can — today, the
    | points a jurisdiction adds to the marketplace go-live checklist. Null (the
    | default) means none: the checklist then holds only the package's own
    | structural points and whatever you added, and says so rather than reading
    | as an all-clear. Shipped profiles: "de".
    |
    | A jurisdiction the package does not ship is supplied by binding your own
    | Pushery\Billing\Contracts\JurisdictionProfile in the container, which wins
    | over this name. A name that is neither shipped nor bound is refused at boot
    | — a typo here must never produce a checklist that quietly skips everything.
    |
    */

    'tax_profile' => env('BILLING_TAX_PROFILE'),

    /*
    |--------------------------------------------------------------------------
    | Small-business thresholds
    |--------------------------------------------------------------------------
    |
    | Read only behind a jurisdiction profile that has such a threshold. Two
    | knobs, and what is deliberately NOT a knob is the basis they are computed
    | on: the same percentages against a different basis fire at a completely
    | different moment, which would look like a configuration choice and behave
    | like a different rule.
    |
    | A declaration expires at the next year boundary plus the grace period. The
    | grace is how long somebody has to answer an obligation that arrives on the
    | first day of the year — never a license to treat last year's answer as this
    | year's.
    |
    */

    'tax_small_business' => [
        // The three small-business thresholds, in the currency's minor units. They are jurisdiction values,
        // not code literals: they change with the law, and a number baked into a class would be a time bomb.
        // Read ONLY behind the German jurisdiction profile (billing.tax_profile = 'de'). Defaults are the
        // German § 19 UStG figures as of 2025: 25.000 € prior-year, 100.000 € current-year, and a 25.000 €
        // immediate limit in the founding year (no pro-rata twelfths — abolished in 2025).
        'previous_year_limit' => (int) env('BILLING_TAX_SB_PREVIOUS_YEAR_LIMIT', 2_500_000),
        'current_year_limit' => (int) env('BILLING_TAX_SB_CURRENT_YEAR_LIMIT', 10_000_000),
        'founding_year_limit' => (int) env('BILLING_TAX_SB_FOUNDING_YEAR_LIMIT', 2_500_000),

        // A consumer that runs the small-business status by hand may turn OFF the automatic flip from KU to
        // standard rating. There is deliberately NO switch for the opposite direction: a platform count under
        // the limit proves nothing (external turnover exists), so an automatic flip BACK is never offered.
        // The asymmetry is an invariant, not a default.
        'auto_flip_enabled' => (bool) env('BILLING_TAX_SB_AUTO_FLIP', true),

        'warning_levels' => [0.80, 0.95],
        // How long a confirmed small-business registration stands before it is asked again. A registration
        // confirmed once is not confirmed forever — registers change, and a standing resting on a
        // two-year-old lookup rests on nothing.
        'eu_revalidate_after_days' => (int) env('BILLING_TAX_EU_REVALIDATE_AFTER_DAYS', 365),

        // There is deliberately no switch for whether a declaration expires at the year boundary. One used
        // to be here, read by nobody, and wiring it would have meant offering to turn off the thing that
        // makes the whole area work: a declaration is a statement about a year in progress, so it cannot
        // outlive that year, and an installation that could disable the expiry would carry standings that
        // read as current and are not. The grace period is the only part that is a choice.
        'reattestation' => [
            'grace_days' => (int) env('BILLING_TAX_REATTEST_GRACE_DAYS', 30),
        ],
    ],

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

    // Whether a prepayment is taxed when the money ARRIVES rather than as the service is rendered. On a year
    // paid up front the two answers are eleven months apart, and nothing on the documents themselves says
    // which was meant. Some jurisdictions require the first; off by default, because a silent change of tax
    // period is the last thing an upgrade should do.
    /*
    |--------------------------------------------------------------------------
    | Reporting: who falls under a platform reporting duty
    |--------------------------------------------------------------------------
    |
    | Which sellers a platform has to report is decided by the active reporting
    | profile, not by this package. With no such profile bound, nobody is
    | reportable — the only safe default, since guessing would hand personal data
    | to an authority under a statute the package knows nothing about.
    |
    | Where a regime exempts small-scale sales of GOODS, both edges have to hold
    | at once, and the operators are configurable because the upper one is where
    | this is easy to get wrong: a statute that exempts a seller who "does not
    | exceed" a figure is exempting the one sitting exactly on it, and a strict
    | comparison would report them. Reporting somebody the law leaves out is its
    | own offense and a data protection breach besides — so "when in doubt,
    | report" is not the careful direction, it is the second mistake.
    |
    | The exemption belongs to the goods branch alone. There is no small-scale
    | relief for commissioned work: three commissions worth a year's rent are
    | reportable, and a thousand standardized downloads are not.
    |
    */

    'reporting' => [
        // How many days before a filing obligation falls due it is announced.
        //
        // The calendar knows the dates; this says how much warning they are worth. Long enough to assemble
        // the figures, short enough that the notice is still about something imminent — a reminder six weeks
        // out is filed away and not seen again.
        //
        // Each obligation is announced ONCE for its date. Two of them share the end-of-January deadline and
        // are announced separately on purpose: different law, different data, and whoever handles the one
        // they thought of must not be able to consider the day dealt with.
        'filing_notice_days' => (int) env('BILLING_FILING_NOTICE_DAYS', 14),

        'goods_de_minimis' => [
            'max_sales' => (int) env('BILLING_REPORTING_MAX_GOODS_SALES', 30),
            'sales_operator' => env('BILLING_REPORTING_SALES_OPERATOR', '<'),
            'max_compensation_minor' => (int) env('BILLING_REPORTING_MAX_GOODS_COMPENSATION_MINOR', 200000),
            'compensation_operator' => env('BILLING_REPORTING_COMPENSATION_OPERATOR', '<='),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | United States regime
    |--------------------------------------------------------------------------
    |
    | OFF by default, and the fields it governs are collected anyway. That split
    | is deliberate: a seller's declaration about where they are taxed is given
    | at onboarding or chased a year later from people who have moved or gone
    | quiet, under a filing deadline — and that chase ends in withholding money
    | from sellers who did nothing wrong.
    |
    | Switching this on is what lets anything ACT on those declarations. Until
    | then the package records them and does nothing else with them.
    |
    | Note what is never stored: the taxpayer identification number itself. It
    | belongs wherever the signed form is kept; a copy here would be a second
    | place to leak it from.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Electronic invoicing
    |--------------------------------------------------------------------------
    |
    | Null asks the active jurisdiction profile, which is where the obligation
    | actually comes from. Unset is NOT the same as false: a consumer who has
    | never heard of an e-invoicing regime has no opinion, and reading their
    | silence as "no" would mean an operator whose jurisdiction requires it
    | silently does not comply.
    |
    | Set it explicitly to be ahead of your own regime, or deliberately behind
    | it while migrating — reasons the package cannot know.
    |
    */

    'e_invoice' => [
        'always' => env('BILLING_E_INVOICE_ALWAYS'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Invoice documents
    |--------------------------------------------------------------------------
    |
    | Where the ISSUED PDF of an invoice is kept, when you keep one.
    |
    | The package stores no PDF. Storage is your decision, with a disk, a
    | retention policy and a bill behind it. What it can do is SERVE what you
    | kept instead of rendering a fresh one — and that difference is the whole
    | point: everything under a renderer moves over the years an invoice must
    | stay readable, so a re-render years later resembles the document your
    | customer holds without being it.
    |
    | Name the disk your `billing_invoices.pdf_path` values address, and the
    | download route serves the stored file. Leave it null — the default — and
    | the route behaves exactly as before: it renders, and no disk is ever
    | touched.
    |
    | A recorded path whose file is GONE renders too, rather than 404-ing an
    | owner out of their own invoice — but it is logged as an error naming the
    | invoice and the path, because a lost archive file is an incident and the
    | quiet version of it is the expensive one.
    |
    */

    'invoices' => [
        // The prefix on a locally issued invoice number: PREFIX-YYYY-0000001. Only a local engine mints
        // these — a provider-driven driver copies the number its provider already issued.
        'number_prefix' => env('BILLING_INVOICE_NUMBER_PREFIX', 'INV'),
        'pdf_disk' => env('BILLING_INVOICE_PDF_DISK'),
    ],

    'tax_us' => [
        'enabled' => (bool) env('BILLING_TAX_US_ENABLED', false),

        // How close to a region's limit counts as "approaching it", in basis points of that limit.
        // Waiting for a limit to be crossed is waiting too long: registration takes weeks, the obligation
        // starts at the crossing, and the gap is a stretch of selling into a region unregistered. The right
        // lead time depends on how fast this operator can actually register, which the package cannot know.
        'activation_share_bps' => (int) env('BILLING_TAX_US_ACTIVATION_SHARE_BPS', 5_000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Reporting counters
    |--------------------------------------------------------------------------
    |
    | Which window a reversal reduces: the one it HAPPENED in ('reversal_period')
    | or the one it CORRECTS ('original_period').
    |
    | This is a setting because it is genuinely undecided, not because both are
    | equally good. A counter is a record of movements and a movement has a date,
    | which argues for the first. But the tax-booking rule that puts a correction
    | in the month it happened answers a different question from a count, and
    | applying it here by reflex is a known way to get this wrong.
    |
    | What hangs on it: a creator just under a threshold whose crossing sale is
    | later refunded. Attributed to the reversal period the crossing stands and
    | the documents issued after it stay correct. Attributed to the original
    | period the year's figure is clean — but unless a crossing that has already
    | happened is explicitly kept, every document issued after it becomes
    | retrospectively wrong at once. The second option is only safe together with
    | that rule, which is the other half of the same decision and is not built.
    |
    | The shipped value is what the package has always done. Changing it is a
    | deliberate act, and the reason it is a key rather than a constant is so the
    | act is visible.
    |
    */

    // Which window a reversal reduces — the one it happened in, or the one it corrects.
    //
    // Read by BOTH counters on this seam, and that is the point of it living here rather than in either of
    // them: the threshold figure and the reporting figure must agree about WHERE a reversal belongs. They
    // still disagree about its size, which is why there are two of them.
    //
    // Two tickets specified this and said opposite things. DECIDED (owner, 2026-07-29): 'original_period' —
    // a reversal reduces the period of the document it corrects. What hangs on it: a creator just under the small-business limit
    // whose December sale tips them over, refunded in February. On 'reversal_period' the crossing stands and
    // the settlements issued in between stay correct, while the year's figure includes turnover that was
    // undone. On 'original_period' the year is clean — and unless a crossing that has already happened is
    // explicitly kept, the tax stated in between becomes retrospectively unlawful across every one of those
    // documents at once.
    //
    // 'original_period' is only safe BECAUSE the other half of that decision also holds: a crossing that has
    // already happened is final. `SmallBusinessAutoFlip` only ever flips forward, and a test pins the two
    // figures disagreeing on purpose — the year reads clean while the breach keeps its date. Setting this
    // back to 'reversal_period' is supported and changes only which window a reversal reduces; what must
    // never happen is the clean year WITHOUT the finality rule.
    //
    // An unreadable value is refused rather than defaulted: both answers are defensible, so neither is a
    // safe reading of a value somebody mistyped.
    'tax_counters' => [
        'reversal_attribution' => env('BILLING_TAX_COUNTER_REVERSAL_ATTRIBUTION', 'original_period'),

        // Buyer gross per subdivision of a destination country — the early warning for an obligation that
        // is reached per state rather than nationally.
        //
        // OFF by default, and the opposite default from the reporting counter on purpose. That one is on
        // because it is what the package has always done; this one is new, most installations have no
        // subdivision-level obligation anywhere, and a counter nobody needs is a column nobody reads.
        //
        // A SIBLING of `dac7`, never a child of `billing.tax_profile` — that key is a scalar, and a child
        // under it breaks every reader that takes the profile name as a string.
        //
        // Independent of the other two switches in both directions: turning this on does not start the
        // reporting counter, and turning the reporting counter off does not stop this. They answer
        // different questions about different parties and share nothing but a config section.
        //
        // It is also independent of any geoblock. A counter that started when a market opened would produce
        // its first useful figure after the first year that could have breached a threshold — which is the
        // year it exists for.
        'us_state_gmv' => [
            'enabled' => env('BILLING_TAX_COUNTER_US_STATE_GMV', false),
        ],

        // The reporting counter, and whether this installation is in a regime that has one at all.
        //
        // On by default, because the default is what the package has always done and a switch that changes
        // behavior on upgrade is not a switch, it is a surprise. A platform outside the EU turns it off and
        // stops carrying a counter for a duty it does not have.
        //
        // OFF MEANS REFUSED, NOT ZERO. Asking a disabled counter for a quarter's figure raises rather than
        // answering nothing — a zero is a real reporting answer ("this seller received nothing"), and it is
        // the one that gets filed. Handing back the same value for "nothing arrived" and "nobody is
        // counting" is how a return goes out stating that every seller earned nothing, with no error
        // anywhere and every figure internally consistent.
        //
        // It gates the REPORTING basis alone. The section 19 threshold counter is a different question on a
        // different basis — whether a creator is still a small business — and it keeps running, which a
        // test asserts by switching this off and reading that one.
        'dac7' => [
            'enabled' => (bool) env('BILLING_TAX_COUNTER_DAC7_ENABLED', true),
        ],
    ],

    'tax_point_on_receipt' => (bool) env('BILLING_TAX_POINT_ON_RECEIPT', false),

    // Whether `billing:rates:probe` may reach the public source that publishes VAT rates.
    //
    // Off by default, and that default is the point: a package should not contact a public service because
    // it happened to be installed. A bare checkout, a fork and every CI run that did not intend this stay
    // silent. Turn it on where the probe is actually wanted — a nightly job, never the push gate, because a
    // network-dependent check in the push gate goes red on the first DNS hiccup and gets disabled.
    'tax_rate_probe' => [
        'enabled' => (bool) env('BILLING_RATE_PROBE', false),

        // Where `billing:tax-rates:check` writes its proposal. Null means beside the shipped snapshot, so the
        // two share a dating convention and can be diffed side by side.
        //
        // Worth setting when the installed package is read-only, or when the next release would take the
        // file with it. A proposal is meant to be reviewed by a person, which can be days later.
        'proposal_path' => env('BILLING_RATE_PROPOSAL_PATH'),
    ],

    // Locally held exchange rates. OFF by default, and the default is a refusal rather than a silence: with
    // this off the bound ExchangeRateSource answers every conversion by saying the package ships no rates
    // and what to do about it.
    //
    // Two reasons it is opt-in rather than simply on. A single-currency install never converts, so it would
    // carry a table and a schedule it never reads. And turning it on means outbound connections on a
    // schedule -- a package that dials out of somebody else's application unasked is an unpleasant surprise,
    // however good its reasons.
    //
    // What it does NOT do is ship rates. The table starts empty and is filled by the importers, from the
    // publishers, into the consumer's own database. Which rate is the correct one is jurisdiction knowledge
    // and the rules contradict each other across jurisdictions, so a package-level default would be wrong
    // for somebody by law rather than by oversight. See the ExchangeRateBasis enum for the three rules.
    'tax_exchange_rates' => [
        'enabled' => (bool) env('BILLING_EXCHANGE_RATES', false),

        // Which currencies `billing:exchange-rates:import` fetches from the central bank, as the bank names
        // them. Empty by default, so the switch above alone still contacts nobody: turning the store on and
        // deciding which currencies you actually settle in are two separate acts, and a package that
        // guessed the second would dial out for currencies nobody sells in.
        //
        // Rates are stored in the direction the bank publishes — euro to each of these — and never turned
        // around, so list the currencies you receive money in rather than the ones you report in.
        'currencies' => [],

        // How many days old the newest imported rate may be before `billing:doctor` calls the series out.
        //
        // Three, deliberately well under the fourteen at which the lookup gives up and a document cannot be
        // issued: three days is two missed daily imports plus a weekend, which is a warning, while fourteen
        // is the incident. The forward window stays the ceiling — a value above it would let the diagnostic
        // stay green while the money path is already refusing documents, so the lower of the two applies.
        'max_age_days' => 3,

        // How far back a scheduled import re-fetches. Deliberately more than a day: a publisher revises, a
        // run gets missed, a machine sleeps through a night. Re-importing is idempotent, so the only cost of
        // an overlap is a few rows rewritten with the same figures — while a window of exactly one day turns
        // any missed run into a permanent hole in the series.
        'lookback_days' => (int) env('BILLING_EXCHANGE_RATES_LOOKBACK_DAYS', 10),
    ],

    'datev' => [
        'consultant' => env('BILLING_DATEV_CONSULTANT'),
        'client' => env('BILLING_DATEV_CLIENT'),
        'account_length' => (int) env('BILLING_DATEV_ACCOUNT_LENGTH', 4),

        // Single-seller accounts: the revenue account (Gegenkonto) and the customer/receivables account
        // (Konto) every invoice books to when no chart of accounts is selected below. This is the shipped
        // default and its output is byte-for-byte unchanged.
        'revenue_account' => env('BILLING_DATEV_REVENUE_ACCOUNT'),
        'customer_account' => env('BILLING_DATEV_CUSTOMER_ACCOUNT'),

        // The chart of accounts whose per-transaction map below is active: 'skr03', 'skr04', or null. Null
        // (the default) uses the single-seller accounts above — the export is byte-identical. Selecting a
        // chart only changes the VALUES resolved per transaction, never the export's structure or field order.
        // The account NUMBERS are German-accountant defaults (SKR03/SKR04) confirmed with the tax advisor,
        // not values the package invents; a consumer with a different frame overrides them here — no code
        // change. These are read only behind the German jurisdiction profile (billing.tax_profile = 'de');
        // a consumer elsewhere runs an empty set and the DATEV export is simply not used.
        //
        // Each account is [account, automatic]. An "automatic" account (Automatikkonto) derives its VAT from
        // the posting itself, so a BU-Schlüssel is NEVER set alongside it — doing so cancels the automatic
        // derivation and is the classic import error.
        'chart' => env('BILLING_DATEV_CHART'),

        // How merchant payables appear in the books. 'collective' (the default) books every merchant against
        // the one creator-liabilities account and leaves the per-merchant detail to this package's own
        // sub-ledger — the arrangement that stays workable at any number of merchants, because an accounting
        // firm's master data does not fill with rows nobody there will ever open. 'individual' gives each
        // merchant their own account from `range_start` upward, for the installation whose accountant expects
        // open items per creditor. The booking logic is identical either way; only the account the payable
        // side resolves to changes. Switching an install that has already booked is a documented migration,
        // not a flag flip: earlier bookings keep pointing at the account they were made against.
        'person_accounts' => [
            'mode' => env('BILLING_DATEV_PERSON_ACCOUNTS', 'collective'),
            'range_start' => (int) env('BILLING_DATEV_CREDITOR_RANGE_START', 70000),
        ],

        'accounts' => [
            'skr03' => [
                'fan_revenue_standard' => ['account' => '8400', 'automatic' => true],
                'fan_revenue_reduced' => ['account' => '8300', 'automatic' => true],
                'commission_revenue' => ['account' => '8510', 'automatic' => true],
                'creator_input_de_standard' => ['account' => '3106', 'automatic' => true],
                'creator_input_exempt' => ['account' => '3109', 'automatic' => false],
                'creator_input_eu_reverse_charge' => ['account' => '3123', 'automatic' => true],
                'creator_input_third_country_reverse_charge' => ['account' => '3125', 'automatic' => true],
                'creator_input_eu_reverse_charge_reduced' => ['account' => '3113', 'automatic' => true],
                'creator_input_third_country_reverse_charge_reduced' => ['account' => '3115', 'automatic' => true],
                // The PSP fee is a §13b input (an Irish supplier's service), booked like an EU input — NOT a
                // money-transfer/bank-charge account. Getting this wrong is the classic audit finding.
                'psp_fee' => ['account' => '3123', 'automatic' => true],
                'money_transit' => ['account' => '1360', 'automatic' => false],
                'creator_liabilities' => ['account' => '1705', 'automatic' => false],
                'voucher_liabilities' => ['account' => '1706', 'automatic' => false],
                'transit_items' => ['account' => '1590', 'automatic' => false],
                'other_income' => ['account' => '2709', 'automatic' => false],
                // The realized currency difference between collecting a sale and paying it out. Its own
                // pair of accounts rather than a net figure on one, because a chart that nets gains against
                // losses cannot answer what either was — and neither carries VAT, so both are non-automatic.
                'exchange_gain' => ['account' => '2660', 'automatic' => false],
                'exchange_loss' => ['account' => '2150', 'automatic' => false],
                // OSS revenue is a block resolved per destination country — no default, since each consumer's
                // OSS registration differs. Configure one account per activated country here.
                'oss_revenue' => [],
            ],
            'skr04' => [
                'fan_revenue_standard' => ['account' => '4400', 'automatic' => true],
                'fan_revenue_reduced' => ['account' => '4300', 'automatic' => true],
                'commission_revenue' => ['account' => '4510', 'automatic' => true],
                'creator_input_de_standard' => ['account' => '5906', 'automatic' => true],
                'creator_input_exempt' => ['account' => '5909', 'automatic' => false],
                'creator_input_eu_reverse_charge' => ['account' => '5923', 'automatic' => true],
                'creator_input_third_country_reverse_charge' => ['account' => '5925', 'automatic' => true],
                'creator_input_eu_reverse_charge_reduced' => ['account' => '5913', 'automatic' => true],
                'creator_input_third_country_reverse_charge_reduced' => ['account' => '5915', 'automatic' => true],
                'psp_fee' => ['account' => '5923', 'automatic' => true],
                'money_transit' => ['account' => '1460', 'automatic' => false],
                'creator_liabilities' => ['account' => '3505', 'automatic' => false],
                'voucher_liabilities' => ['account' => '3506', 'automatic' => false],
                'transit_items' => ['account' => '1370', 'automatic' => false],
                'other_income' => ['account' => '4830', 'automatic' => false],
                'exchange_gain' => ['account' => '4840', 'automatic' => false],
                'exchange_loss' => ['account' => '6880', 'automatic' => false],
                'oss_revenue' => [],
            ],
        ],
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

    // The countries this platform is registered in, and therefore may sell into.
    //
    // Absent by default, which means no gate at all: a market check that defaulted to closed would stop
    // every existing install at its next sale, having been asked for nothing. That is an outage, not a
    // guard. Configure the map and the gate becomes fail-closed within itself — anything not explicitly
    // `open` is refused, INCLUDING a country the evidence could not resolve, because not knowing where a
    // buyer is is the clearest reason not to sell rather than a reason to guess.
    //
    // Each entry is an ISO 3166-1 alpha-2 code mapped to `open` (registered, sales allowed), `planned` (on
    // the roadmap, still refused) or `blocked`. Three states rather than two so a reader can tell an
    // intention from a decision — a planned market silently read as closed for good is how an opening gets
    // forgotten.
    //
    // A market opened here that the local rates cannot price refuses the boot. That combination is the
    // dangerous one precisely because neither half looks wrong: the market is open, the calculator answers,
    // and the answer is zero.
    //
    // Note the key: a SIBLING of `billing.tax`, never a child of it. `billing.tax` is a scalar read in four
    // places, and nesting under it would turn it into an array — the tax calculator would fall through to
    // its no-tax branch and the guard behind it would return early, producing 0% on every invoice with no
    // error anywhere.
    // How the buyer's country is established, and how contradicting signals are settled.
    //
    // Three sources speak at the moment of sale and nowhere else: what the buyer said, where their payment
    // instrument is issued, and where the connection appeared to be. None can be reconstructed afterwards —
    // the raw address is discarded as soon as it has become a country — so a sale that did not record them
    // has no evidence of where it happened and no way to obtain any later.
    //
    // `required_signals` is how many must name a country before a sale can rest on them. The legal answer
    // depends on turnover — one below a threshold, two non-contradicting above it — so the package ships the
    // stricter setting: too much evidence costs a checkout question, too little costs a defensible position.
    // Consumer-withdrawal rights (a digital work's 14-day right and how it extinguishes). This is
    // consumer law, not tax law, and the two do not move together — an operator can run one country's VAT
    // and another's consumer regime — so it is its OWN profile and NOT tied to the marketplace switch: a
    // single seller in Germany needs it just as much. Off by default (`profile` null): no extra checkout
    // step, no changed receipt, byte-identical. Set a profile and the gate becomes fail-closed — a work
    // whose right extinguishes on delivery is not provided until the buyer's double consent is recorded.
    'consumer_rights' => [
        'profile' => env('BILLING_CONSUMER_RIGHTS_PROFILE'),
        // There is deliberately no withdrawal-window length here, and the reason is no longer that one
        // cannot be computed — it is that the length is not a setting. Which sales have a window at all,
        // and how long it runs, are a jurisdiction's reading: the profile answers both through
        // `StatesWithdrawalWindow`, and a number in this file would either duplicate it or contradict it.
        //
        // The window is measured from PROVISION and frozen onto the grant as
        // `billing_access_grants.withdrawal_window_ends_at`. Provision, not purchase and not payment: for a
        // pre-ordered work those are three different days, and a window anchored to the sale would have
        // expired before the buyer could open anything.
        //
        // (This paragraph used to say the package could not compute a window because nothing recorded the
        // moment a work was provided. That was true when it was written and stopped being true when the
        // grant register landed — `acquired_at` is written immediately after the fail-closed withdrawal
        // gate, which IS that moment. It is recorded here because a reason does not age visibly: for weeks
        // it went on reading as a decision rather than as a gap, and the gap survived because of it.)

        // How long a seller owes CONFORMITY updates on a sale — defect fixes, security fixes, staying
        // compatible. A different axis from what the creator sells: `content_ownership.default_update_policy`
        // governs new editions and added material, which may be withheld, while this governs what is owed
        // afterwards regardless.
        //
        // Ships EMPTY, and empty is not an oversight. The obligation runs for as long as a buyer may
        // reasonably expect, "reasonably" is a judgement about a kind of product, and no statute states a
        // number — so a package that shipped one would be inventing a legal answer and hiding it in a
        // library. Empty means no end has been established, and updates keep flowing: the direction that
        // cannot harm a buyer. Set it once you have taken advice for your product class.
        'conformity_update_period_days' => env('BILLING_CONFORMITY_UPDATE_PERIOD_DAYS'),

        // Whether a buyer can validly agree to give up that obligation AT ALL in your jurisdiction.
        //
        // There is deliberately no operator-wide off switch for conformity updates — that is exactly the
        // blanket arrangement the law refuses to recognize, and offering it as a setting would let a legal
        // obligation be configured away. This flag does not switch anything off: it only makes a
        // grant-by-grant waiver POSSIBLE, and recording one still needs a reference to an actual agreement
        // made separately and before the contract. A flag in a file cannot produce a declaration.
        //
        // Off, because whether SECURITY fixes can be waived at all is genuinely disputed. Turning it on is
        // your decision, taken on your own advice.
        'allow_conformity_waiver' => env('BILLING_ALLOW_CONFORMITY_WAIVER', false) === true,
    ],

    'tax_evidence' => [
        'required_signals' => (int) env('BILLING_TAX_EVIDENCE_SIGNALS', 2),

        // Whether a sale's SUBDIVISION -- a US state -- is recorded alongside its country.
        //
        // On, and the reason it is on is that it cannot be turned on retroactively. A US sales-tax nexus is
        // measured per state over a rolling window, so the question "have we crossed the threshold in Texas"
        // can only be answered from history -- and the evidence is written once at the sale, with the raw IP
        // deliberately discarded. A state not captured then is gone. A counter built afterwards could only
        // fill an `unknown` bucket while looking as though it worked, and a nexus warning that starts
        // counting when the market opens warns after the threshold instead of before it.
        //
        // It changes nothing you did not already supply. The package has no input finer than the country and
        // does not go looking for one: if your signals carry no subdivision, nothing is written. And it is
        // recorded only for the countries below, only from the sources that named THAT country, only when
        // they agree, and only as the ISO 3166-2 suffix -- never a postcode, a city or a coordinate.
        //
        // Turn it off where your reading of data minimization differs. The rest of the evidence is
        // untouched, and a state counter then runs honestly on `unknown` rather than quietly on a guess.
        'collect_subdivision' => env('BILLING_TAX_EVIDENCE_SUBDIVISION', true),

        // Which countries' subdivisions are worth recording at all.
        //
        // A list rather than "everywhere one exists", because almost every country has subdivisions and
        // almost none of them decide anything this package is asked about. The US is here because its sales
        // tax is administered per state and the registration duty follows a per-state threshold; adding a
        // country here should follow the same test -- a question the subdivision actually answers.
        'subdivision_countries' => ['US'],
    ],

    'tax_markets' => null,

    // Content ownership: what a buyer OWNS, as opposed to what their plan lets them do.
    //
    // Two questions that sound alike and are not. The licensing side (`config/license.php`, reached through
    // the License contract) answers "what may this owner DO right now", and its answer changes the moment
    // their tier does. This answers "what did this person BUY, and is it still theirs" — a fact that
    // outlives the plan, the creator's account, and the work's own publication.
    //
    // Off by default, and off is FAIL-CLOSED rather than merely unused. With the switch off the read seam
    // resolves to a reader that answers no to everything — an answer, not a resolution error, so "off" and
    // "miswired" stay distinguishable at the call site. The three effects that would otherwise write or
    // withdraw a grant (a completed purchase, a refund, a chargeback) read this key first and return before
    // touching anything.
    //
    // So turning it on is what makes the register exist for an installation, and turning it off is a
    // guarantee about behavior rather than an absence of code.
    //
    // NAMING — deliberate deviation, worth reading once. The ticket that specified this layer called the
    // key `billing.entitlements.enabled`, while its own opening says the collision with the existing
    // Entitlements/License contracts was "deliberately avoided". Both cannot hold: this file's header says
    // "keep entitlements in license.php", and an arch guard enforces that billing never reads
    // `config('license.*')`. So the key is named after what it actually gates. A key that re-imported the
    // word would guarantee that somebody eventually reads a tier check as proof of ownership, which is the
    // one confusion the whole separation exists to prevent.
    'content_ownership' => [
        'enabled' => (bool) env('BILLING_CONTENT_OWNERSHIP_ENABLED', false),

        // What a sale promises about later versions when neither the work nor the merchant says otherwise.
        // One of: latest, latest_with_revisions, windowed, frozen.
        //
        // This is the ENRICHMENT axis — new editions, added material — and it is the creator's to choose. It
        // is NOT the conformity axis: a defect fix or a security patch is owed regardless of what stands
        // here, which is why there is no value that switches those off.
        //
        // A misspelt value is refused at first use rather than defaulted. Falling back to `latest` would
        // promise free updates forever on every sale the install ever makes — the most expensive possible
        // reading of a typo, and one nothing else would surface.
        'default_update_policy' => env('BILLING_DEFAULT_UPDATE_POLICY', 'latest'),

        // Whether a work added to a bundle later reaches people who bought the bundle earlier.
        //
        // Off, because a bundle is normally what it was on the day it was bought. Bundle grants are
        // materialised at purchase, so off costs nothing to enforce -- a work added next month simply has no
        // row for an earlier buyer, and there is nothing to remember to switch off. On, a repeat call for the
        // same buyer tops up what has since been added.
        'bundle_additive_default' => env('BILLING_BUNDLE_ADDITIVE_DEFAULT', false) === true,

        // Whether a refund also ends access to the work.
        //
        // On, but genuinely switchable, because both answers are somebody's deliberate policy. Leaving access
        // in place after a goodwill refund is common and often the point -- the work has already been read, so
        // taking it back costs nothing to skip and turns a recovered customer into an angry one. Ending it is
        // right where the refund was a return rather than a gesture.
        'revoke_on_refund' => env('BILLING_REVOKE_ON_REFUND', true) !== false,

        // The same question for a lost dispute, and a different situation: a chargeback is involuntary,
        // decided by somebody else, and the money is already gone. There is no version of it where the
        // platform chose to give the work away, which is why the shipped answer is to end access.
        'revoke_on_chargeback' => env('BILLING_REVOKE_ON_CHARGEBACK', true) !== false,
    ],

    // Multi-merchant: a buyer pays this platform, and the money is destined for somebody else.
    //
    // Off by default, and "off" means absent rather than neutral. With `enabled` false, no routing field
    // is ever assembled, no marketplace table is read, and a single-seller install is byte-for-byte what
    // it would be if this section had never been written. That promise is what every option below is
    // measured against, and it is why the switch exists instead of a set of individually harmless
    // defaults that add up to a second code path nobody asked for.
    //
    // THE FLAG ALONE DOES NOT ROUTE MONEY, and that is deliberate. The marketplace path hangs off an
    // optional driver contract (`RoutesMoney`, which supplies a `MarketplaceRails`). A driver that does
    // not implement it has no rails to route through, so no configuration here can push a payment at a
    // connected account it cannot reach. A driver that is handed a routing it cannot serve must THROW
    // rather than complete the payment unrouted: silently ignoring one settles the whole amount on the
    // platform account and the merchant is never paid, with nothing in the result saying so.
    //
    // The settings below are not one feature. They fall into three groups, and the middle one is the
    // reason this block is long:
    //
    //   - the money flow — `charge_type`, `fee`, `buyer_fee`, `negative_balance`, `buyer_protection`,
    //     `custody`. Which provider mechanism moves the merchant's share, and what the platform keeps.
    //   - WHOSE SUPPLY IT IS — `seller_of_record`, `regime`, `charge_type_by_posture`, `tax_status_hold`.
    //     These decide who the buyer contracts with, who owes the VAT, and which documents exist at all.
    //     They are not presentation: a pairing the posture table forbids is refused before a payment is
    //     assembled, because a settled sale cannot be re-classified afterwards — moving it does not adjust
    //     a number, it makes every document already issued describe a transaction that did not happen.
    //   - the paperwork — `numbering`, `self_billing`, `receipts`, `fallback`, `vouchers`, `seller_record`,
    //     `seller_activity`, `seller_data_escalation`.
    //
    // Choosing the charge type is a liability decision, not a technical one: it moves the merchant of
    // record between the platform and the connected account, and with it who the buyer's receipt names,
    // who bears a dispute, and who pays the provider's processing fee. Which posture a platform should
    // declare is a jurisdiction question — the decision matrix lives in the documentation, not here.
    //
    // Full prose: https://docs.pushery.com/billing-for-laravel/marketplace/overview
    // Driver authors: https://docs.pushery.com/billing-for-laravel/guides/upgrading
    'marketplace' => [
        'enabled' => (bool) env('BILLING_MARKETPLACE_ENABLED', false),

        /*
        | Buyer protection: the payout waits until the buyer says they got what
        | they bought, or until enough time passes that their silence counts as
        | consent. The money is NEVER held by this application — it stays with
        | the payment provider and a release is an instruction to it. Holding
        | other people's money is a regulated activity, and doing it by accident
        | is doing it without a license.
        |
        | "account_type" must be one that lets a payout be held back at all;
        | accounts that pay out on the provider's own schedule are refused
        | rather than run as protection that only appears to work.
        |
        | "decide_after_days" is the deadline nothing can stop, and it has to
        | finish inside "provider_limit_days" with "margin_days" to spare: past
        | that wall the money goes out whatever this says.
        */
        // Which money flow this installation uses with its provider. It is a money-flow decision made with
        // the provider, not a per-sale one — and it has to agree with the seller-of-record posture, which is
        // checked before a routed payment is assembled rather than after one has been sent.
        // Defaults to separate transfers because the shipped posture whitelist holds only
        // `platform_deemed_supplier`, and `charge_type_by_posture` below does not permit that posture on a
        // destination charge. It shipped as `destination` and was therefore self-contradictory: the one
        // pairing the table forbids was the one an untouched installation produced. Nothing caught it,
        // because the guard that checks the pair had no call site until 2026-07-25.
        //
        // HEADS UP — which ENTRY POINT you use matters on this shape, and one of the two refuses on purpose.
        //
        // Separate transfer takes the whole payment and then makes a SECOND provider call to move the
        // merchant their share. That call ships: `StripeMerchantTransfers::transferShare()`, bound
        // unconditionally, made by `RoutedPayment::charge()` — which is the supported way to start such a
        // sale, and the one that writes the ledger row the transfer is reconciled against.
        //
        // `PaymentRails::charge()` refuses it, and that refusal is PERMANENT rather than a placeholder: the
        // transfer can only go out after the payment has succeeded, which is after that method has already
        // returned. A rail that accepted the routing would take the buyer's money and have no moment left in
        // which to pay the merchant.
        'charge_type' => env('BILLING_MARKETPLACE_CHARGE_TYPE', 'separate_transfer'),

        'buyer_protection' => [
            /*
            | Whether the merchant's share WAITS instead of moving the moment the payment succeeds.
            |
            | OFF by default, and the default is the whole safety of this switch: with it off the payment
            | path is byte-identical to what it always was, so no existing installation changes behavior on
            | an upgrade. Turning it on is a decision about when a seller is paid, which is not one a package
            | may make on an operator's behalf.
            |
            | It applies to the `separate_transfer` lane only — the one where this package moves the share
            | itself. On a destination charge the provider moves the money as the payment settles, and there
            | is no moment in between for anything here to hold.
            */
            'enabled' => (bool) env('BILLING_BUYER_PROTECTION', false),
            'account_type' => env('BILLING_BUYER_PROTECTION_ACCOUNT_TYPE', 'express'),
            'confirm_after_days' => (int) env('BILLING_BUYER_PROTECTION_CONFIRM_AFTER_DAYS', 14),
            'decide_after_days' => (int) env('BILLING_BUYER_PROTECTION_DECIDE_AFTER_DAYS', 60),
            'provider_limit_days' => (int) env('BILLING_BUYER_PROTECTION_PROVIDER_LIMIT_DAYS', 90),
            'margin_days' => (int) env('BILLING_BUYER_PROTECTION_MARGIN_DAYS', 20),
        ],

        /*
        | A merchant who owes the platform money — a clawback that could not take
        | it back from the provider. Offsetting the debt against their next
        | settlement is the default; switching it off is a commercial choice, not
        | a technical one, and the debt then stands as a claim to pursue rather
        | than quietly disappearing.
        |
        | "claim_after_days" is how long a debt may sit untouched before it counts
        | as a receivable to chase. How long a platform waits is its own terms —
        | baked into code it would silently apply somebody else's.
        */
        'negative_balance' => [
            'offset_against_payouts' => (bool) env('BILLING_MARKETPLACE_OFFSET_DEBT', true),
            'claim_after_days' => (int) env('BILLING_MARKETPLACE_CLAIM_AFTER_DAYS', 90),
        ],

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

        // What a platform asks a seller for at onboarding.
        //
        // The fields a reporting duty adds are collected from EVERY seller by default, not only from the
        // ones the duty currently covers. A seller's classification changes the day they take on different
        // work, and a platform that only asked the sellers it already knew about then has to chase the
        // rest — after the year has closed, under a deadline, from people who have gone quiet. That chase
        // ends in withholding money from sellers who did nothing wrong.
        //
        // Switch it off and those fields are asked for only once a duty actually applies. That is a real
        // choice with a real cost on both sides: asking somebody for an identifier no law demands is an
        // imposition, and not asking means the later chase. The fields needed to settle at all — where to
        // send the document, where to send the money — are never affected either way.
        /*
        | Where a produced seller-reporting record is copied to, beside the row that
        | keeps its exact bytes.
        |
        | Null is a supported answer rather than a missing setting: an operator whose
        | accounting collects the file from the record itself needs no second copy, and
        | writing one anyway would put a document containing sellers' figures somewhere
        | nobody asked for it. The record is kept either way — the copy is a convenience,
        | never the evidence.
        */
        'reporting' => [
            'export_disk' => env('BILLING_REPORTING_EXPORT_DISK'),
            'export_path' => env('BILLING_REPORTING_EXPORT_PATH', 'reporting'),
        ],

        'seller_record' => [
            'collect_precautionary' => (bool) env('BILLING_MARKETPLACE_COLLECT_PRECAUTIONARY', true),
        ],

        // When a seller is active enough to be asked to declare their standing.
        //
        // These numbers drive ONE rule: when to ask a seller to declare their standing. It fires as soon as
        // EITHER measure is reached — a platform setting, meant to be early, since asking a question sooner
        // than strictly necessary costs nothing. Move them freely; they are yours.
        //
        // They do NOT set the reporting duty's de-minimis exemption, and the similarity is a trap worth
        // naming here because the two read almost identically. That boundary is set by law, holds only
        // while BOTH measures stay under, is inclusive at its money figure, and lives under
        // `billing.reporting.goods_de_minimis.*` — a separate family, deliberately.
        //
        // They used to be coupled: a second copy of the exemption read THESE keys, so moving the
        // declaration trigger moved the statutory boundary with it, in the over-reporting direction.
        // Reporting data that need not be reported is itself an incorrect report and a data protection
        // breach at the same time, so that direction is not the cautious one. The copy is gone.
        //
        // The test is activity, never intent. Somebody who sells regularly is trading whether or not they
        // make anything on it.
        'seller_activity' => [
            'sales_threshold' => (int) env('BILLING_MARKETPLACE_SALES_THRESHOLD', 30),
            'proceeds_threshold_minor' => (int) env('BILLING_MARKETPLACE_PROCEEDS_THRESHOLD_MINOR', 200000),
        ],

        // What happens when a seller does not supply the data a reporting duty needs.
        //
        // Reminders go to everyone with an incomplete record — asking is free and the data is wanted. The
        // MEASURE only follows where the missing data is legally required of that seller: suspending an
        // account or holding somebody's earnings over data nobody is entitled to demand is not compliance,
        // it is withholding a service on the strength of a rule that does not apply to them. Extending it
        // anyway is a contract question between a platform and its sellers, so it is off by default.
        //
        // A withholding is capped by the money rail's own deadline. "Held until they cooperate" is
        // open-ended and the rail is not; a hold that outran it would not be stricter, it would be a
        // payment nobody can complete.
        'seller_data_escalation' => [
            'first_reminder_after_days' => (int) env('BILLING_MARKETPLACE_FIRST_REMINDER_DAYS', 7),
            'second_reminder_after_days' => (int) env('BILLING_MARKETPLACE_SECOND_REMINDER_DAYS', 30),
            'measure_after_days' => (int) env('BILLING_MARKETPLACE_MEASURE_AFTER_DAYS', 60),
            // suspend_sales | withhold_payout — two very different impositions, and neither is obviously
            // the gentler one: stopping somebody selling ends their income, holding their money leaves them
            // selling and unpaid.
            'measure' => env('BILLING_MARKETPLACE_DATA_MEASURE', 'withhold_payout'),
            'measure_precautionary_gaps' => (bool) env('BILLING_MARKETPLACE_MEASURE_PRECAUTIONARY', false),
            'withhold_up_to_days' => (int) env('BILLING_MARKETPLACE_WITHHOLD_UP_TO_DAYS', 90),
            // The rail's own limit. Read here, defined by the payout schedule — this never sets it.
            'payout_deadline_days' => (int) env('BILLING_MARKETPLACE_PAYOUT_DEADLINE_DAYS', 90),
        ],

        // What happens while a merchant's tax standing is unestablished. Both locks default ON, and both
        // are INERT under a jurisdiction profile that requires the hold — turning payouts back on there
        // would amount to choosing a default standing for people whose standing nobody knows.
        //
        // There is deliberately no key naming a default standing. Both possible defaults are wrong in
        // opposite directions: one states tax that is not owed and lands the liability on the recipient,
        // the other understates a real one. A consumer who wants no hold changes the profile, rather than
        // inventing an answer to a question that was not asked.
        // When nobody knows how a merchant's supply is taxed, neither selling on their behalf nor paying
        // them out is safe — there is no conservative guess, because assuming they charge tax normally and
        // assuming they do not produce errors that point in OPPOSITE directions.
        //
        // `enforce_from` is the date the hold starts biting, and it is null until you set one. That is not
        // the switches below being ignored: they say WHAT is held, this says FROM WHEN, and with no date
        // the answer is "not yet". The separation exists because of what happens on the day it starts. A
        // merchant with no recorded standing is `Unclarified`, which is exactly the state that blocks — so
        // on an established marketplace, switching this on with today's date stops every creator who has
        // not yet declared, all at once, for something they were never asked for.
        //
        // So pick a date far enough out to collect declarations, tell the merchants who are missing one,
        // and let the date arrive. `billing:marketplace:preflight` reports an unset date as outstanding
        // rather than as configured, because a hold that never begins is one nobody can rely on.
        //
        // A jurisdiction profile that REQUIRES the hold overrides all three of these: there the hold is a
        // legal condition rather than a rollout, and a date cannot postpone it.
        'tax_status_hold' => [
            'blocks_payouts' => (bool) env('BILLING_MARKETPLACE_HOLD_BLOCKS_PAYOUTS', true),
            'blocks_sales' => (bool) env('BILLING_MARKETPLACE_HOLD_BLOCKS_SALES', true),
            'enforce_from' => env('BILLING_MARKETPLACE_HOLD_ENFORCE_FROM'),

            // How many days before that date the merchants who have not declared are told it is coming.
            //
            // The instruction above says to tell them; this is the part that says when. Too early is
            // forgotten by the time it matters, and too late is not a warning — a merchant needs longer to
            // produce a declaration than a checkout takes to fail.
            //
            // Nobody is warned while `enforce_from` is unset: with no date there is no deadline to warn
            // about, and inventing one to have something to say is worse than silence.
            'warn_days_before' => (int) env('BILLING_MARKETPLACE_HOLD_WARN_DAYS_BEFORE', 30),
        ],

        // Which shape a routed sale has: the platform reselling in its own name (`commission_chain`), or
        // the platform arranging somebody else's sale (`intermediation`). The two produce different
        // documents, different turnover, and different parties on a receipt — so a sale is classified once,
        // at the sale, and the answer is frozen onto the document it produces.
        //
        // The allow-list works like `allowed_postures` above and for the same reason: falling into a regime
        // is never acceptable. A platform that has not said it arranges other people's sales must not begin
        // to because a product was classified in a way nobody looked at.
        'regime' => [
            'default' => env('BILLING_MARKETPLACE_REGIME', 'commission_chain'),
            'allowed' => ['commission_chain'],
        ],

        // The visible prefix for each document series the platform numbers itself.
        //
        // The keys are the document ROLES (see the DocumentSeries enum); the values are a jurisdiction's
        // letters. These are the German defaults — buyer receipt F, self-billed invoice G, private
        // settlement note A, commission invoice P, and a K-prefixed correction series paired to each. A
        // consumer elsewhere maps the same roles to their own letters. A role with no configured prefix is
        // refused at allocation rather than numbered with a blank, so a missing entry cannot mint a
        // malformed number that then has to be corrected — which is itself a numbered event.
        //
        // The number format is PREFIX-YYYY-####### (a seven-digit running number that restarts each year
        // per series). Gaps are harmless; a duplicate or a renumbering is not, and neither can happen: the
        // sequence only ever advances, and a written number is frozen.
        'numbering' => [
            'series' => [
                'buyer_receipt' => 'F',
                'self_billed_invoice' => 'G',
                'settlement_note' => 'A',
                'commission_invoice' => 'P',
                'buyer_receipt_correction' => 'KF',
                'self_billed_invoice_correction' => 'KG',
                'settlement_note_correction' => 'KA',
                'commission_invoice_correction' => 'KP',
            ],
        ],

        // Whether a self-billed document requires a prior agreement with the creator.
        //
        // On by default, and it opts out only explicitly. A self-billed document is an invoice only if both
        // sides agreed to the arrangement before it; a document issued without that agreement is not an
        // invoice and cannot be repaired. A jurisdiction that does not demand the agreement turns this off —
        // but never implicitly: a missing or non-boolean value keeps the requirement, because the fail-safe
        // is to require it. The clause text and the onboarding screen belong to the consumer; the package
        // ships only the record and the guard.
        'self_billing' => [
            // Whether the platform settles creators by self-billing at all. On by default: a marketplace in a
            // jurisdiction where self-billing is the norm issues the documents itself. A consumer that does
            // NOT self-bill turns this off and stays in the fallback lane — the creator submits their own
            // invoice — and never calls the engine. It is a backstop, not the routing decision: the caller
            // checks this before settling, and the engine refuses loudly if it is reached while off, rather
            // than issue a document a disabled platform never meant to.
            'enabled' => (bool) env('BILLING_MARKETPLACE_SELF_BILLING_ENABLED', true),

            'require_agreement' => (bool) env('BILLING_MARKETPLACE_SELF_BILLING_AGREEMENT', true),
        ],

        // What the buyer's receipt collects, and when.
        //
        // A consumer sale carries no invoicing duty, so the receipt is chosen from the purchase and
        // collects the least data: a small domestic purchase gets a simplified receipt, a larger or
        // cross-border one a plain payment record, and only a buyer who asks for a full invoice has their
        // name and address collected. The threshold below is the gross, in minor units, up to and INCLUDING
        // which a domestic purchase gets the simplified receipt — the German § 33 UStDV figure, €250.00, so
        // it is profile knowledge, not a universal.
        'receipts' => [
            'small_amount_threshold_minor' => (int) env('BILLING_MARKETPLACE_RECEIPT_SMALL_AMOUNT_MINOR', 25_000),
        ],

        // The fallback lane — where a creator the platform does not self-bill submits their own invoice.
        'fallback' => [
            // How far, in minor units, a submitted invoice's net or tax may deviate from what the creator
            // earned that period before it is a review finding. The default is exact (0): the platform pays
            // out what the creator earned, not what they wrote, and a mismatch holds the payout.
            'tolerance_minor' => (int) env('BILLING_MARKETPLACE_FALLBACK_TOLERANCE_MINOR', 0),
        ],

        // Which quantity stays fixed when the same thing is sold into markets with different tax rates.
        //
        // Something has to move: the buyer's price, the creator's payout and the tax cannot all stay put
        // when the rate changes. `uniform_gross` keeps one price everywhere and lets the payout absorb the
        // difference — the default, because a price that varies by country is visible to buyers and hard to
        // explain, and the creator sees the variation per position on their statement rather than as an
        // unexplained total. `uniform_payout` keeps the payout predictable and moves the buyer's price.
        //
        // A creator names their target PAYOUT rather than the buyer's price. That direction is what makes a
        // change in their own tax standing visible: naming a fan price leaves it fixed while the payout
        // moves underneath them.
        'pricing' => [
            'mode' => env('BILLING_MARKETPLACE_PRICING_MODE', 'uniform_gross'),
        ],

        // Which money routing is compatible with which declared seller.
        //
        // Two independent axes: the charge type decides who the PROVIDER treats as the merchant of record,
        // the posture decides who the DOCUMENTS name as the seller. Neither determines the other — for
        // electronic services the seller is assigned by law regardless of how the money flows — and because
        // they are independent they can diverge. A pair that disagrees is not an error anybody sees; it is
        // a receipt and a settlement describing different transactions, found in an audit.
        //
        // The default reads: a destination charge makes the connected account the merchant of record, so it
        // fits a posture where the merchant is the seller. A platform that is the deemed supplier must take
        // the whole payment itself and move the merchant's share separately, whatever it would prefer.
        //
        // This is a table, not wiring: a consumer whose legal reading differs changes values here.
        'charge_type_by_posture' => [
            'destination' => ['seller_of_record', 'platform_intermediary'],
            'separate_transfer' => ['platform_deemed_supplier', 'platform_intermediary'],
        ],

        // Tips and pay-what-you-want: a fan-chosen amount run through the ordinary sale pipeline, not a
        // donation side path. Off by default. A tip takes the ordinary commission unless a tip rate is set;
        // a PWYW price is floored on the SERVER, because a buyer-chosen price is the one place the
        // anti-injection stance would otherwise lapse.
        'tips' => [
            'enabled' => (bool) env('BILLING_MARKETPLACE_TIPS', false),
            // ONE LINE ON PURPOSE -- DO NOT WRAP THIS TERNARY.
            // A `: null` sitting alone on its own line is UNREACHABLE COVERAGE, not a missing test.
            // PHP emits no opcode for a constant-null false branch, so pcov never reports that line,
            // while PHPUnit's static analyzer counts it as executable. The result is a line that
            // reads 0 % forever and cannot be covered by any test that could ever be written --
            // measured directly: `: null` is absent from pcov's line map entirely, where `: 7` and
            // `: strlen('abc')` in the same position are both counted. Wrapped, this single line
            // takes the whole package below its 100 % floor and no test can lift it back.
            'commission_bps' => env('BILLING_MARKETPLACE_TIPS_COMMISSION_BPS') !== null ? (int) env('BILLING_MARKETPLACE_TIPS_COMMISSION_BPS') : null,
        ],
        'pwyw' => [
            'minimum_minor' => (int) env('BILLING_MARKETPLACE_PWYW_MINIMUM_MINOR', 0),
        ],

        // The fee the BUYER pays on a C2C sale — a separate supply from the seller-side commission, and
        // the platform's own first supply in the intermediary posture. Off by default. Its place of supply
        // is where the mediated sale happens, not where the buyer banks, and it is quoted gross. Kept on
        // its own revenue account because netting it into the item price or the seller's turnover would
        // make a taxable supply of the platform's own disappear — the account NUMBER is config, the
        // separateness is structure.
        'buyer_fee' => [
            'enabled' => (bool) env('BILLING_MARKETPLACE_BUYER_FEE', false),
            'model' => env('BILLING_MARKETPLACE_BUYER_FEE_MODEL', 'percent'),
            'bps' => (int) env('BILLING_MARKETPLACE_BUYER_FEE_BPS', 0),
            'fixed_minor' => (int) env('BILLING_MARKETPLACE_BUYER_FEE_FIXED_MINOR', 0),
            'revenue_account' => env('BILLING_MARKETPLACE_BUYER_FEE_ACCOUNT', '8510'),

            // WHERE THE MEDIATED SALE HAPPENS — the fee's place of supply, and NOT where the buyer banks.
            // A mediation is supplied where the transaction it mediates is, so the buyer's own seat never
            // moves this. Left unset, the shipped checkout states the sale's currency region, which is what
            // that lane actually knows about the transaction; an installation that can answer more precisely
            // sets it, or supplies its own checkout.
            'place_of_supply' => env('BILLING_MARKETPLACE_BUYER_FEE_PLACE'),
        ],

        // THE SELLER'S COMMISSION — the platform's second intermediation supply, and the mirror of the
        // buyer fee above. Under intermediation the platform arranges somebody else's sale and charges the
        // seller for arranging it; that fee is a taxable supply of the platform's own, and the seller needs
        // a document for it to deduct the tax on what was kept from them.
        //
        // Off by default, so no existing installation changes: with this switched off a mediated sale still
        // produces exactly the one document it produces today.
        //
        // TWO DIFFERENCES FROM THE BUYER FEE, both deliberate. A fixed commission is CAPPED by the sale — it
        // comes out of the payout, and a fee larger than the sale would owe the seller a negative amount.
        // And the calculator REFUSES outside intermediation rather than returning nothing: a commission in
        // the commission chain is the named red line, and refusing before a number is drawn keeps a gapless
        // series from spending one on a document that must not exist.
        'seller_fee' => [
            'enabled' => (bool) env('BILLING_MARKETPLACE_SELLER_FEE', false),
            'model' => env('BILLING_MARKETPLACE_SELLER_FEE_MODEL', 'percent'),
            'bps' => (int) env('BILLING_MARKETPLACE_SELLER_FEE_BPS', 0),
            'fixed_minor' => (int) env('BILLING_MARKETPLACE_SELLER_FEE_FIXED_MINOR', 0),

            // Its own account, because it is its own supply. Booking both intermediation fees to one account
            // would make the two indistinguishable in an export that has to tell them apart.
            'revenue_account' => env('BILLING_MARKETPLACE_SELLER_FEE_ACCOUNT', '8511'),
        ],

        'fee' => [
            // Which side of an uneven percentage split keeps the leftover minor unit. A commercial
            // per-transaction rounding leaves one cent to assign, and at volume that assignment is real
            // money, so it is a documented contract choice, not an accident of code:
            //
            //   platform_first — the residual cent goes to the fee (the platform). This is the default,
            //                    matching the per-transaction commercial rounding decided for the ledger.
            //   creator_first  — the residual cent goes to the net (the creator). This is the only order
            //                    that hits an exact target payout, at the cost of the platform's cent; a
            //                    consumer using it must show the RESULTING payout, never treat the input as
            //                    a promise.
            'rounding' => env('BILLING_MARKETPLACE_FEE_ROUNDING', 'platform_first'),

            // What happens to the platform's own commission when a sale is unwound:
            //
            //   refund — the platform gives it back in proportion to what was refunded. The default,
            //            because it is the only value under which a refund nets to zero across the three
            //            parties: buyer made whole, merchant returns their share, platform returns its cut.
            //   retain — the platform keeps it, on the ground that it performed the handling and a refund
            //            does not undo that. Legitimate, but the merchant is then short by the retained
            //            amount, so a consumer choosing it must be able to show them why.
            //
            // NOT available under the commission_chain regime, and refused at preflight rather than at the
            // first refund. Retaining a fee presupposes a document the platform issued the merchant for a
            // service; a commission chain has none — the platform buys and resells, and unwinding the sale
            // unwinds both supplies. Money kept afterwards sits on no supply at all, which is turnover on a
            // tax return with nothing behind it.
            'refund_policy' => env('BILLING_MARKETPLACE_FEE_REFUND_POLICY', 'refund'),

            // What the platform keeps, as basis points and a flat amount. BOTH, because both are ordinary:
            // the provider's own pricing is a percentage plus a fixed amount per transaction, and a
            // marketplace that could express only one of them would have to approximate the other.
            //
            // Both default to ZERO, and that is the neutral position rather than a placeholder. A package
            // that shipped a take rate would be choosing a consumer's commercial terms for them, and a rate
            // nobody set is far more likely to be an oversight than an intention.
            //
            // The rate is a NET rate: it is applied to the transaction's net, not to what the buyer paid.
            //
            // It is also a GROSS take: what the platform keeps BEFORE the provider's own processing fee.
            // Under the shipped account type (`marketplace.onboarding.account_type` = `express`) that fee
            // comes out of the platform's balance — measured, see that setting — so the margin is this
            // figure minus the provider's percentage and per-transaction amount. The package does not
            // subtract it, and could not: it does not know your pricing with the provider, and inventing a
            // deduction would make every payout figure wrong by a guess. On a small sale the difference is
            // the whole margin.
            //
            // TWO LANES CANNOT HONOR THAT, and saying so here is the point. Stripe's hosted subscription
            // takes a percentage of the invoice TOTAL, which includes the buyer's tax; the hosted one-off
            // purchase needs an absolute fee at the moment the session opens, which is before the buyer's
            // rate is a fact at all. Both therefore compute on the gross. The routed money path — the one
            // that writes the ledger — computes on the net as stated. Which answer the package should settle
            // on is open; until then this comment is the only place a reader can learn that the promise has
            // an exception, and a promise with an undisclosed exception is how the two bases diverged in the
            // first place.
            'default_bps' => (int) env('BILLING_MARKETPLACE_FEE_BPS', 0),
            'default_flat_minor' => (int) env('BILLING_MARKETPLACE_FEE_FLAT_MINOR', 0),

            // Who economically bears the payment provider's own processing fee. This is SEPARATE from the
            // platform's commission and changes the net arithmetic: under a destination charge the provider
            // deducts it from the connected account unless the application fee covers it.
            //
            // The default follows the market: the merchant bears it, as they would selling anywhere else.
            // The package only records which side carries it — it never invoices the provider's fee, which
            // the provider bills the platform directly.
        ],

        // Who holds the money on a routed sale. The safe default is that the payment provider holds it end
        // to end (the platform never has other people's funds on its own account); the package ships only
        // this path. Holding funds on a platform-owned account is a regulated activity in most
        // jurisdictions, so `platform_held` is refused at boot unless the host binds a
        // PaymentServiceLicenseAttestation — the package will not let an unaware consumer become an
        // unlicensed money holder by flipping a flag. There is deliberately no yield/interest option: paying
        // interest on held funds is a further regulated activity the package does not offer.
        /*
        | Vouchers. OFF by default, and deliberately so: a balance customers pay
        | into is a supervised question the moment it can be recharged, cashed
        | out or handed on. This one can do none of those — there is no method
        | for any of them, and a guard test holds the absences — but it is still
        | something to switch on knowingly rather than to find running.
        |
        | "instrument_type" decides WHEN the tax falls, and it is frozen on each
        | voucher at issue: a supply already made cannot be re-decided by a later
        | change here. Where you sell into many countries at many rates, neither
        | the place nor the rate is fixed when a voucher is sold, so nothing is
        | taxed yet — that is "multi_purpose". With exactly one country and one
        | rate the supply IS determined at issue, and "single_purpose" is right.
        |
        | The reporting threshold is a supervisory figure, not a package one: the
        | counter produces a defensible number and raises the alarm; filing
        | anything is a decision a person makes.
        */
        'vouchers' => [
            'enabled' => (bool) env('BILLING_VOUCHERS_ENABLED', false),
            'instrument_type' => env('BILLING_VOUCHER_INSTRUMENT_TYPE', 'multi_purpose'),
            'expire_after_days' => (int) env('BILLING_VOUCHER_EXPIRE_AFTER_DAYS', 1095),
            'volume_window_months' => (int) env('BILLING_VOUCHER_VOLUME_WINDOW_MONTHS', 12),
            'volume_threshold_minor' => (int) env('BILLING_VOUCHER_VOLUME_THRESHOLD_MINOR', 100_000_000),
            'volume_warn_at_percent' => (int) env('BILLING_VOUCHER_VOLUME_WARN_AT_PERCENT', 80),
        ],

        'custody' => [
            'platform_held' => (bool) env('BILLING_MARKETPLACE_PLATFORM_HELD', false),
        ],

        // Provider events ABOUT A MERCHANT arrive on their own endpoint, signed with their own key. The
        // separation is not cosmetic: a verifier taught to accept either secret would let anyone holding
        // the merchant key forge a platform event, and a platform event moves the platform's own money.
        //
        // The secret is REQUIRED in production once the marketplace is on — the app refuses to boot without
        // it rather than run a marketplace whose merchant events all fail verification in silence.
        'webhook' => [
            'path' => env('BILLING_MARKETPLACE_WEBHOOK_PATH', 'billing/webhook/marketplace'),
            'secret' => env('BILLING_MARKETPLACE_WEBHOOK_SECRET'),
        ],

        // Giving a merchant an account at the provider and driving its hosted identity flow.
        'onboarding' => [
            // Which kind of provider account a merchant gets. This decides who runs the identity checks and
            // who absorbs a loss, and a provider will not change it once a merchant has onboarded — so an
            // unsupported value is refused at BOOT rather than defaulted quietly.
            //
            //   express  — the provider owns onboarding, identity and the merchant's own dashboard. The
            //              default: the least the platform has to build, and the MOST it carries.
            //   standard — the merchant has a full account of their own with the provider, and a direct
            //              relationship with them. More capable for the merchant, less controllable here.
            //
            // This setting is ALSO where the money risk is decided, which is not obvious from the names.
            // Measured against the live API on 2026-08-06 (pinned version 2025-08-27.basil), identical for
            // DE and US accounts, and readable on any account as `controller.fees.payer` and
            // `controller.losses.payments`:
            //
            //   express  — the PLATFORM pays the provider's processing fee, and the PLATFORM absorbs a
            //              chargeback. Neither is visible in a charge; both are properties of the account.
            //   standard — the merchant pays the fee, and a loss is not debited from the platform balance.
            //
            // So a platform on `express` nets its commission MINUS the provider's fee, and carries the
            // disputes. `billing.marketplace.fee` states a GROSS take rate; the package cannot
            // subtract a processing fee it does not know your pricing for. Price accordingly — that
            // difference is the whole margin on a small sale.
            'account_type' => env('BILLING_MARKETPLACE_ACCOUNT_TYPE', 'express'),
        ],

        // The go-live checklist that gates the switch above. `php artisan billing:marketplace:preflight`
        // prints it; with `enabled` true, an open blocking point refuses the boot and names itself.
        'preflight' => [
            // Points nobody can check from here — terms published, a registration filed — recorded as your
            // own dated, versioned statement. Keyed by checkpoint key, each entry:
            //
            //   'registrations.oss' => [
            //       'version'     => '2026-07',    // must match what the point currently requires
            //       'attested_at' => '2026-02-14', // the day it was actually done (YYYY-MM-DD, mandatory)
            //       'reference'   => '…',          // optional: where it is recorded on your side
            //   ],
            //
            // The version is the expiry: when a release changes what has to be attested, the requirement
            // moves, the recorded version stops matching, and the point goes red until somebody re-reads
            // and re-attests. An attestation that never expires is a tick nobody looks at twice.
            'attestations' => [],

            // Blocking points you deliberately proceed without, by checkpoint key. Reserved for a point
            // whose subject is your own jurisdiction or contract — the package cannot know that your
            // country has no equivalent obligation. A waived point is still evaluated and still prints why
            // it did not hold; it is demoted to a warning, never to a pass.
            //
            // Structural points cannot be waived: a driver does not learn to route money because its key is
            // in this list, so an entry naming one is reported as a failure rather than ignored.
            'waived' => [],
        ],
    ],

];
