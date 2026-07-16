# Changelog

All notable changes to `pushery/billing-for-laravel` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and
the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-07-16

### Added

- **The account-hub UI is an optional dependency.** The billing core — models, webhooks, invoicing, tax and
  the contracts — needs no Livewire; adopt the package without it and wire your own UI, or install
  `livewire/livewire` and get the nine ready-made hub screens. Registration is guarded, so a core-only
  install never loads a screen.
- **A usage-history account screen.** `/account/usage/history` shows an owner's usage across finished billing
  periods plus their add-on top-up timeline, read straight from the stored counters — never a provider call —
  behind a project-bindable `UsageHistoryProvider` seam.
- **Irreversible cancellation re-confirms the acting user's identity.** The immediate-cancel action verifies
  a password (or, for an account that signs in with a provider, the account email), rate-limited per user and
  never stored — so a hijacked session cannot end someone's billing without proving who is behind it.
- **`PricingCatalog::cards()` — one config-authoritative source for the in-app upgrade grid and a public
  `/pricing` page.** Each tier's feature bullets live in config as an ordered list of translation keys (your
  app owns the strings, in every locale), resolved by `PricingCatalog::bulletsFor()`; an optional
  `highlight`/`badge` per tier emphasises a card. Because both surfaces render from the same `PricingCard`
  read-model and the bullets come only from config, the grid and `/pricing` cannot drift into different
  promises. A malformed `features` entry yields no bullets rather than a raw key on the page.
- **Customers are warned BEFORE a metered allowance runs out, instead of first learning about it by being
  refused.** Crossing a meter's warn threshold now sends a `QuotaWarningNotification` with the meter and the
  numbers that matter — once per meter per period, claimed with a single conditional update on the counter
  row, so two requests crossing the threshold at the same instant warn once and the next period warns again.
  Recording usage can never fail because a warning could not be delivered: the usage is already on the books.
  The threshold itself is now configurable per meter (`tiers[].metered[].warn_threshold`, default 0.8, and
  validated) — it used to be a hardcoded 0.8 that no config could move.
- **Customers are welcomed when their subscription goes live**, naming the tier they are now on — deduped
  once per subscription, so recovering from a past-due state back to active does not re-welcome them.
- **Customers are now told when a payment SUCCEEDS, and when a cancellation takes their access away.** Both
  notices already shipped but nothing ever sent them: `PaymentSucceededNotification` (the receipt — the other
  half of the money conversation the package only had the failure side of) and `SubscriptionCanceledNotification`
  (with the date access actually ends — the part customers write in about). Two new webhook effects wire them:
  `SendPaymentReceipt` (dedups on the payment reference, so a provider redelivery cannot send a second receipt
  for money paid once) and `SendSubscriptionCanceledNotice` (fires on the grace state only, and says nothing
  when there is no end date to announce). Rebindable through the new `ReceiptNotifier` / `SubscriptionNotifier`
  seams.
- **The scheduled `billing:run` cycle advance now runs `withoutOverlapping`**, like every other scheduled
  command — so a cycle advance that runs long can never have a second copy start on top of it and
  double-advance the same due subscriptions.
- **A `billing.dunning` middleware sends a past-due owner somewhere they can fix it.** Put it on the
  surfaces that need a working card: a browser request from an owner whose payment has failed
  (`past_due`/`incomplete`) is redirected to the payment-recovery screen, while an API/JSON request gets a
  `402 Payment Required` (configurable via `billing.dunning_status`). The recovery screen itself is never
  blocked, so there is no redirect loop, and the decision reads only the local subscription row — no
  provider call on the hot path.
- **`EntitlementsResolver` — an owner-facing numeric-limit guard.** `limit($owner, $key)`,
  `remaining($owner, $key, $used)` and `allows($owner, $key, $used, $delta = 1)` resolve the owner's tier
  and check a proposed usage against the tier's ceiling (uncapped → always allows; otherwise
  `used + delta <= limit`), so a consumer enforces a limit the same way everywhere instead of re-writing the
  `<`/`<=` comparison per call site. The count of what's used stays the project's `UsageProvider`; only the
  comparison lives here. The comparison math carries 100% mutation coverage (every off-by-one mutant killed).
- **The account hub degrades one failing panel instead of 500-ing the whole page.** A `DegradesGracefully`
  concern gives a screen a per-panel error boundary: when a panel's data assembly fails for a reason outside
  the app's control (a provider API blip, a project's own `UsageProvider` throwing), the failure is reported
  and that panel shows an inline "temporarily unavailable" notice while the rest of the screen renders — so a
  customer who came to cancel is not blocked by a usage gauge that could not load. Every account-hub screen
  with a provider read now uses it — usage, subscription (next-invoice preview), invoices, payment methods and
  payment recovery — so a provider outage degrades one panel to a notice instead of 500-ing the page; the
  `account.degraded` (and usage-specific `account.usage.unavailable`) strings ship in all seven locales.
- **A `BillingMetrics` read-model — MRR and subscription counts for your admin dashboard.**
  `BillingMetricsReporter::compute()` returns active subscriptions, trials, count in dunning, churn over a
  trailing window, and MRR — all from the local subscription rows, with no provider round-trip. MRR is the
  declared list price monthly-normalized (a yearly tier ÷ 12) summed in `billing.currency`: what the catalog
  says you charge, independent of a provider coupon or proration.
- **Two operator commands: `billing:datev:export` and `billing:tier:grant`.** `billing:datev:export` writes
  a period of issued invoices as a DATEV EXTF "Buchungsstapel" — with no `--from`/`--to` it exports the
  previous calendar month, so a monthly cron hands last month's bookings to the tax advisor; drafts (no
  issue date) are excluded and an out-of-order or unparseable period is refused. `billing:tier:grant` is the
  terminal form of a support comp (`BillingAdmin::comp`): it writes the tier column and records the grant on
  the audit trail, refuses a tier key no `billing.tiers` entry declares, and warns when the tier is not in
  `billing.untouchable_tiers` (where the next provider webhook could overwrite it).
- **The usage screen now shows a meter's prepaid balance.** When an owner bought prepaid units of a meter
  (an add-on that grants units, not money), the usage screen shows that rolling balance alongside the cycle
  usage — the units they paid for and still hold, distinct from the per-cycle included allowance which resets.
- **A self-contradictory billing config now fails at boot instead of silently mis-tiering a customer.** A
  `BillingConfigValidator` checks the invariants a misconfiguration would otherwise break quietly — the
  `zero_tier` is a defined tier, every `untouchable_tiers` entry exists, a tier references only defined
  dimensions, each `warn_threshold` is within 0..1, the dunning `after_days` strictly ascend, `owner` is
  `user` or `team`, and every `price_display.currency` is a valid ISO 4217 code — and refuses to boot with a
  clear message on a violation. It is a no-op on the shipped default, so a fresh install boots clean.
- **A tier or add-on `provider_price` can now be a per-provider map, not just one id.** Declare
  `'provider_price' => ['stripe' => 'price_...', 'mollie' => 'ord_...']` and the new `ProviderPriceResolver`
  hands each driver its own price; a scalar id still means "one price for the active driver". The tier/add-on
  key stays the only thing the client submits — the price is always resolved from config (anti-price-injection).
- **A local tax mode can no longer silently under-collect.** Tax is a driver capability: `provider` defers
  to a provider that computes it (Stripe Tax), `eu_oss` computes VAT from a local table, `none` adds nothing.
  A local mode configured on a driver that hands the charge to the provider (Stripe) would compute VAT the
  provider never charges — the customer is under-charged and nothing looks broken until the return does not
  add up. The package now refuses to boot on that combination (a `TaxSupportGuard`, like the metering guard),
  and symmetrically refuses `provider` on a driver that computes no provider tax. A local tax figure reaches
  an invoice only on a driver that produces the invoice itself.
- **`Billing::fake()` — a recording test fake for the money seams.** One call binds a fake to `Checkout`,
  `SubscriptionActions` and `OneTimeCharge`, and gives ready-made assertions
  (`Billing::assertSubscribeStarted($owner, 'pro')`, `assertSwapped`, `assertCanceled`, `assertPurchased`,
  `assertNothingCharged`, …) — the same shape as `Bus::fake()`. The documented `Event::fake()` /
  `Mockery::spy` recipe still works; this is the convenience layer.

- **Reverse-charge invoices now render correctly per EN 16931.** An intra-EU B2B reverse charge is emitted
  as VAT category `AE` at 0% with the exemption reason (BT-121 `VATEX-EU-AE` / BT-120 "Reverse charge"),
  not the zero-rated `Z` a conformant validator rejects. The `reverse_charge` fact is frozen on the invoice
  (read from Stripe's `customer_tax_exempt`). Issued invoices are now GoBD-immutable: their number, amounts,
  currency, tax treatment, date and lines cannot change after they are recorded (the status still may).

- **Coupons are actually applied at Stripe checkout.** A coupon code entered on the plan screen is resolved
  by the `DiscountResolver` and, when mapped (`billing.coupons.<code>.stripe_coupon`), passed to Stripe as a
  Checkout Session discount — Stripe owns the money math and its native redemption limit.

- **Generic trials (a free trial with no subscription) now work end to end.** An owner mid-trial used to
  resolve to "never subscribed" and get no access — nothing built a subscription state from the owner when
  there was no subscription row, so the `generic_trial` state was unreachable. Now `Trials::grant($owner)`
  starts a trial on the owner's own `trial_ends_at`; while it runs, the owner resolves to the `GenericTrial`
  state (which grants access) and the tier resolver unlocks the configured `billing.trial.generic_tier`; when
  it lapses the owner falls to `churned` (a customer on file) or `none`. Generic trials are opt-in — without
  a `generic_tier` there is nothing to unlock, so granting one is a no-op.

- **A subscription trial can now skip the up-front card.** `billing.trial.requires_payment_method` (default
  `true`) controls whether checkout collects a payment method before the trial; set it `false` and Stripe
  collects the card only if the trial converts (`payment_method_collection: if_required`).

- **The trial policy is fully configurable, globally and per tier.** A single `TrialPolicy` now resolves the
  trial length (`billing.trial.days`), its kind (`billing.trial.mode`: `none` / `subscription` / `generic`)
  and whether a card is required, each overridable per tier under `billing.tiers.<key>.trial`. The default is
  no trial. `mode` is derived when unset — a configured `generic_tier` implies a generic trial, a positive
  length alone implies a subscription trial — so a generic-trial app never *also* attaches a subscription
  trial at checkout (which would trial the owner twice).

- **The account screens now show exactly one trial call-to-action per state.** While an owner is on a trial,
  the subscription overview shows a single policy-driven CTA — subscribe (generic trial), add a payment method
  (a card-free subscription trial), or review the plan (a trial with a card on file) — the plan screen shows
  the days remaining plus the card hint, and the usage screen notes that the usage shown is the trial tier's
  entitlement. All localized in the seven shipped locales and theme/token-aware.

- **The trial-ending reminder is now actually sent.** `TrialEndingNotification` existed but nothing ever
  dispatched it — a customer's trial could lapse into a first charge with no warning. The Stripe
  `customer.subscription.trial_will_end` webhook now maps to a neutral `TrialEnding` domain event, and a
  registered effect sends the reminder (localized, transactional/non-suppressible) with the trial end date.
  It is sent once per trial end, not once per provider redelivery, and only after the delivery transaction
  commits. A host can swap the delivery via the new `TrialNotifier` seam.

- **Per-seat billing now stays in sync with a team's actual membership.** A team could add or remove members
  all cycle and keep being billed for whatever seat count the provider happened to hold — the seat-sync logic
  existed but nothing ever called it. The wiring is now complete:
  - A `HasSeats` trait derives `seatCount()` from a configurable active-members relation
    (`billing.seats.membership_relation`); pending invites do not occupy a paid seat, and a team never bills
    below one. Adopt it on a team model — no migration, the package does not own your membership table.
  - A provider-neutral seat-sync service reconciles the billed quantity to the seat count only when they
    actually differ, firing a `SeatQuantityChanged` event on a real change. It delegates the provider call to
    a `SeatBilling` seam (Stripe books proration natively) and **refuses, loudly, to bill below the occupied
    seat count** — a silent under-bill is exactly what must not happen quietly.
  - A queued, after-commit `SyncSeatsOnMembershipChange` listener runs the re-sync on your team join/leave
    events. Name them in `billing.seats.membership_events` (and, for an event that cannot implement the
    `AffectsSeats` contract, the listener reads the team off a configured property).

- **Metered usage now has drift guards, so unbilled usage surfaces as an alert instead of on the customer's
  invoice.** The local ledger and the provider's meter are two sources of truth that can quietly diverge —
  a report accepted on our side that never arrived, a meter that stopped rating. Three additions close the
  gap:
  - `billing:usage:reconcile` (scheduled daily) reads the provider's own aggregate back for every owner,
    meter and current cycle and compares it against what was reported — netted of prepaid units, so a
    prepaid customer is not falsely flagged. Any disagreement raises a `UsageReconciliationDrift` event and
    the command exits non-zero. It also raises `UsageBacklogStalled` when the oldest unreported usage has
    been held longer than `billing.metering.stall_hours` (default 6) — past the point it can still be
    billed. Both events dispatch through the framework, so a host app can listen or alert on them.
  - `billing:meters:check` now verifies each metered price against the provider as well as the meter: that
    the price is meter-backed and its meter matches `provider_meter`, that the currency matches the tier's
    `unit_price`, and — the one that hides — that the graduated first tier's free allowance equals the
    tier's `included`. The allowance lives in the provider's price; `included` only drives the gauge, so a
    mismatch silently gives the customer a different number of free units than the interface promises.
  - An `invoice.upcoming` webhook now force-flushes that customer's usage outbox immediately, so usage lands
    on the invoice being finalized rather than a cycle late — after finalization a meter event is not
    retro-billed at all.

- **An add-on can grant usage UNITS instead of money credit.** Configure
  `billing.addons.<key>.grants = ['meter' => 'emails', 'units' => 1000]` and buying it tops up a prepaid
  balance for that meter rather than the owner's money balance. The rules: the tier's per-cycle `included`
  allowance is spent FIRST and the bought units only after it (so free units are never left unused while the
  customer's own burn); `included` expires with the cycle but **prepaid never does** — paid is paid; and
  prepaid-covered usage is **netted out before the provider is told about it**, because the provider's price
  knows nothing about prepaid and would otherwise bill the customer a second time for units they had already
  paid for. The coverage is carried per usage event, so a second flush inside the same cycle cannot subtract
  the same units twice. A refund claws back only the units NOT yet consumed, proportionally to the money
  returned — the ones already spent delivered their value.
  The reservation lock now defends the prepaid balance alongside the cycle counter, so a bought unit cannot
  be sold to two concurrent requests; that guarantee is proven with two real connections on PostgreSQL and
  MySQL, because SQLite compiles `lockForUpdate` to nothing and could never show it.

- **The read-only dunning gate is resolvable.** `DunningGuard` — which answers whether an owner's dunning
  state blocks access, from the local subscription row alone (no provider call) — now has a default binding,
  so a consuming app can `app(DunningGuard::class)->blockingState($owner)` to gate a feature on it. It had an
  implementation but no binding, so resolving the contract threw.

- **A payment method being removed now notifies the owner.** When a card is detached or a mandate is revoked
  (`payment_method.detached`), the owner is told a payment method was removed from their account — a prompt to
  re-add one before the next renewal fails, and a security signal if they did not remove it themselves.
  Without it the loss was only noticed reactively, when the renewal charge failed. The owner is resolved from
  the event's `previous_attributes` (the customer the method was attached to before the detach), so no local
  mandate store is needed. Adds a `MandateNotifier` seam — separate from `DunningNotifier`, so a consumer that
  implemented the latter is unaffected — with a localized default notification (seven languages). A SEPA
  mandate that goes inactive while its method stays attached (`mandate.updated`) still surfaces reactively via
  dunning; mapping it needs a local mandate store and is left for when a flow needs it.

- **Usage drift guards — `billing:meters:check` and `billing:usage:reconcile`.** Metered billing had no way
  to notice its two silent failures. `billing:meters:check` verifies every configured `provider_meter` exists
  and is active at the provider (a new provider-neutral `MeterInspector` seam, backed by Stripe's meter list),
  so a metered tier pointing at a meter that was never created — or was later archived — is caught by a deploy
  check instead of by an under-charged invoice a month later; it exits non-zero on a miss.
  `billing:usage:reconcile` surfaces usage that was recorded but never billed (rollups the flusher gave up on),
  sums the unbilled quantity, and with `--redrive` returns them to pending so the next flush retries them once
  the cause is fixed.

- **A real audit trail — who did it, not just what happened.** The billing ledger recorded a handful of admin
  actions and nothing else: three of the four webhook effects wrote nothing (including the tier change
  itself), the in-app cancels and swaps wrote nothing, and there was no actor — so "why is this customer on
  Free?" could not be answered, and "the customer canceled" could not be told from "an admin canceled them".
  Every money movement and entitlement change is now recorded with an ACTOR (the specific user or agent) and
  a SOURCE (customer / admin / webhook / system): plan grants and revokes, credits and clawbacks, dunning
  notices, in-app cancels/resumes/swaps, comps, refunds and erasure. The ledger is append-only — a row can
  never be updated and can only be deleted by retention pruning or an erasure request, enforced by a model
  guard and an architecture test, so it is tamper-evident. `billing.audit.level` chooses `money` (the
  default: every money/entitlement event, never noisy) or `all` (also the high-volume navigational events).
  `billing:prune` ages it out on a retention window (`billing.retention.audit_days`, default ten years), and
  `billing:export` includes an owner's audit history in their subject-access export.

- **Add-on credit is now actually spendable, and visible.** A one-time add-on credited the owner's balance,
  but nothing ever read it — the credit sat in a database column the customer could neither see nor spend.
  The balance is now mirrored onto the Stripe customer balance through a new provider-neutral `CreditSync`
  seam, so it is applied automatically against the customer's next invoice, and the account hub shows it
  ("You have €5.00 in account credit — applied to your next invoice"). The push is idempotent (the reference
  is the Stripe idempotency key, in the request header where a retry cannot double-credit), and it mirrors a
  clawback the same way when a refunded add-on's credit is reversed. The package ledger stays the source of
  truth: a driver that cannot hold a provider balance (`DriverCapabilities::supportsProviderCredit` false)
  binds a no-op sync and the balance stays local, so earning and showing credit works on every driver.

- **The package now persists the invoices it renders.** XRechnung and DATEV always existed as renderers, but
  nothing ever wrote the table they read — e-invoicing was a renderer with no data. A webhook effect now
  persists every invoice Stripe finalizes as an immutable `InvoiceRecord`: its provider number (never a second
  one of our own), its buyer snapshot frozen at finalization (§14 UStG), and its line and tax breakdown. It is
  idempotent on the provider invoice id, so a redelivery — or the finalize-then-pay pair of webhooks Stripe
  sends for one invoice — converges to a single row that ends up paid. The tax is derived as total minus
  subtotal, so a Stripe API change to the tax field can never silently zero it. Adds the neutral
  `InvoiceFinalized` event and `InvoiceSnapshot`, provider-neutral so a future driver produces them too.

- **Credit notes are now persisted when money goes back.** A refunded invoice used to leave the books
  overstating turnover: the charge was recorded, the credit was not. When a provider issues a credit note
  (Stripe's `credit_note.created`), the package now stores it as its own `InvoiceRecord`, linked to the
  invoice it credits and to that invoice's own number. DATEV books it as a Haben (credit) rather than a Soll,
  and XRechnung renders it as an EN 16931 credit note (type code 381) that references the original invoice —
  the amounts stay positive, because the document type, not a sign, carries the credit meaning. It is
  idempotent on the provider credit-note id, and the buyer is copied from the invoice's frozen §14 buyer.
  This is the accounting counterpart to the existing refund handling (which moves the money): the two are
  separate concerns, and a credit note carries the line and tax detail a raw refund event does not. Adds the
  neutral `InvoiceCredited` event and `CreditNoteSnapshot`, provider-neutral so a future driver produces them.

- **The Stripe API version is now pinned by the package, not inherited from the SDK.** Stripe versions its
  API by date, and the shape of a webhook payload follows the version — so a routine `composer update` of
  `stripe/stripe-php` in a consuming app could silently move the version the package's webhook mapper parses
  against, and a removed field makes a real billing event quietly stop firing rather than erroring. The
  version is now a package constant (overridable via `billing.stripe.api_version`), sent on every Stripe
  call, and an architecture test refuses any code that reads the version from the SDK instead. Moving it is
  a deliberate act to be proven against the live-Stripe suite. `stripe/stripe-php` is now a direct
  dependency (it arrived only transitively via Cashier before), and a Renovate rule isolates its updates so
  the live-Stripe suite runs before any SDK bump merges.

- **Erase and export an owner's billing data** — `billing:erase {owner}` and `billing:export {owner}`. The
  package stores personal data on your behalf (the buyer on an invoice; the raw webhook payloads, which carry
  the customer's email, name, billing address and card last four) and until now offered no way to answer a
  GDPR request about any of it — while `$user->delete()` left seven tables orphaned and the owner's own stored
  provider API keys sitting in the database with no owner at all.
  The erase deliberately KEEPS the invoices: a valid invoice must carry the buyer's name and address (§14
  UStG) and must be kept for years (§147 AO, §14b UStG), and the right to erasure yields to a legal retention
  obligation (Art. 17(3)(b)). Those rows are unlinked from the owner and kept; everything else is purged, the
  webhook payloads are scrubbed, and a credit balance the customer was still owed is written to the audit
  ledger before it goes rather than vanishing quietly. Deleting the customer at the provider is opt-in
  (`billing.erasure.forget_customer`) because it is irreversible and cancels their live subscriptions there.

- **Guardrails around erasure and retention**, because it is critical and EU law leads. The package now
  refuses to boot if the financial-record retention window is set below the ~10-year statutory floor
  (§147 AO, §14b UStG) unless an operator opts in on purpose (`billing.retention.allow_below_statutory_minimum`)
  — the defaults are a floor, and keeping data longer is always allowed. `billing:erase` asks for
  confirmation before erasing in production (bypass with `--force` for an automated pipeline). And an
  architecture test refuses any owner-keyed `billing_*` table left unclassified for erasure — the exact way
  a table would otherwise be silently skipped.

- **`billing:doctor`** checks that your Stripe webhook endpoints render payloads in the API version the
  package is pinned to. The pin controls the version we SEND, but a webhook payload's shape follows the
  version of the ENDPOINT that receives it — a setting that lives at Stripe — so an endpoint left on an
  older version delivers a shape the mapper was not written for, and the failure is silent (a real event
  just stops firing). The command surfaces that drift with a non-zero exit for CI.

- **`billing:prune`** (scheduled daily) is the retention clock: it ages out stored webhook payloads
  (`billing.retention.webhook_payload_days`, default 90 — long past the provider's own redelivery window,
  which is the only reason they are kept) and removes an erased owner's retained financial records once the
  law no longer requires them. A payload whose effects are still owed is never pruned, however old it is.

- **A hard limit that actually holds.** `UsageRecorder::meter($owner, 'emails', 5_000, fn () => $mailer->send($batch))`
  claims the allowance under a row lock BEFORE the work runs, records what the work really consumed, and
  hands the rest back — reserve 5 000 sends, make 4 812, and the other 188 return to the allowance. The
  quota gate shipped earlier is a point-in-time read: two simultaneous requests read the same number and
  both pass it, so an owner could beat a hard-stop limit by exactly the number of requests they fired in
  parallel. `meter()` is the oversell-safe path; the gate is the cheap pre-check in front of it. If the
  work throws, the allowance is handed back and nothing is billed. A hold that is never settled EXPIRES
  (`billing.usage.hold_seconds`, default 15 minutes) and is reclaimed by `billing:usage:flush` — a worker
  killed mid-request must not cost a paying customer the rest of their month. The guarantee is proven
  against real PostgreSQL and real MySQL with two concurrent connections, because SQLite compiles
  `lockForUpdate` away and cannot prove it at all.

- **Webhook effects no longer take each other down, and failed ones are no longer lost.** Every effect a
  webhook triggers (sync the plan, credit the add-on, send the dunning notice) now runs in its own queued
  job. Before, they ran in a loop inside the provider's own HTTP request: one effect that threw aborted
  every effect after it and answered the provider a 500, and the retry re-ran the effects that had
  already succeeded. Now an effect that fails fails alone, retries on its own (`billing.webhooks.tries`),
  and the provider gets its 204 immediately — a slow effect can no longer hold the request open or read
  as an outage. Point billing work at its own queue with `billing.webhooks.connection` / `.queue`.

- **`billing:webhooks:replay`** re-drives stored deliveries whose effects failed. Every verified delivery
  is now recorded with its raw payload, so work can be redone from what the provider already sent — long
  after the provider has stopped redelivering (Stripe gives up after about three days). Select with
  `--failed`, `--event=<id>`, `--since`; preview with `--dry-run`. Replay is safe to run twice: it goes
  through the same ledger the live path does, so an effect already handled is skipped and nobody is
  credited or mailed a second time.

- **A webhook effect ledger** (`billing_webhook_effect_runs`) records what each effect did, still owes, or
  failed at, per provider reference — which is what makes both the retry and the replay safe.

- **Seven shipped languages.** The account hub and its emails are now translated into English, German,
  Spanish, French, Italian, Dutch and Portuguese, informal register throughout — a consumer gets all
  seven out of the box. A locale-parity test keeps every locale in exact key parity with the source, so
  a new string can never ship half-translated.

- **`billing:sync`** reconciles subscriptions from the provider onto the local rows — the bulk version of
  the post-checkout reconcile, for backfilling after a webhook outage. It applies each subscription
  through the same plan-sync effect the webhook uses, so its recency guard means a sync can never
  overwrite a newer webhook state; it only moves a stale row forward. One owner's provider hiccup is
  reported and skipped rather than aborting the sweep. Scope with `--owner`, preview with `--dry-run`.

- **Escalating dunning.** A scheduled `billing:dunning:advance` walks the dunning ladder for delinquent
  owners: each day it sends the next rung's suspension warning once its `after_days` is reached, and
  charges that rung's configured late fee (added to the next invoice via a new `LateFees` seam — a no-op
  by default, a Stripe pending invoice item when Stripe is active). Before this, an owner got one notice
  on day zero and then silence until a surface returned `423 Locked` with no warning. The rung reached is
  tracked on the subscription and resets when it recovers, so each warning fires exactly once and a
  relapse restarts the escalation. The escalation rides a new `SuspensionNotifier` contract, separate
  from the published `DunningNotifier`, so nothing a consumer already implemented breaks.

- **The quota is actually enforced now.** A `billing.quota:<meter>` route middleware (and a `UsageGate`)
  refuses a request that would take an owner past a BLOCKING metered allowance, so the four metering
  policies finally differ: hard-stop and refuse block (a configurable 429 by default), degrade serves but
  flags the response, fair-use never blocks. The gate is a point-in-time pre-check the app pairs with
  recording; an app needing oversell-safe atomicity still reserves through `UsageMeter`. Adds
  `UsageGate`, the `QuotaExceeded` exception and the `QuotaDecision` value object.

- Card-expiry awareness on the payment-methods screen: a stored card now shows an "expired" or "expires
  soon" badge instead of a bare date, computed from a card being valid through the end of its printed
  month. Card expiry is the biggest source of involuntary churn. Adds `PaymentMethod::expiresAt()`,
  `hasExpired()` and `isExpiringWithin()`.

- A scheduled `billing:cards:warn` command (daily) proactively emails an owner whose default card is
  about to expire — the preventable half of involuntary churn — with a configurable window
  (`billing.cards.warn_within_days`, default 30) and a `--dry-run`.

- The usage screen's over-limit callout now reflects the meter's policy: a hard limit reads as danger, a
  degrade as a warning, and a fair-use (soft) allowance as neutral information ("billed beyond it")
  rather than an alarming red — the same allowance was previously coloured as an error for every policy.

- **A refunded, disputed or admin-refunded add-on now claws back the credit it granted.** When a
  one-time add-on's charge is refunded (`charge.refunded`), a dispute over it is lost
  (`charge.dispute.closed` with status `lost`), or a support agent refunds it through `BillingAdmin`, the
  credit is reversed automatically, keyed on the payment reference the purchase recorded. A won dispute
  reverses nothing. Stripe reports a cumulative refunded total, so a partial refund reverses only its
  part, a lost dispute after a partial reverses the rest, and a redelivery reverses nothing — and a
  refund of anything that is not a tracked add-on reverses nothing at all. The reverse, the credit debit
  and the audit line commit in one transaction, so a mid-way failure rolls the whole thing back rather
  than marking the purchase reversed without clawing the credit. The credit balance is allowed to go
  negative (a customer refunded credit they already spent owes it back). Adds `CreditLedger::debit()` and
  `AddonPurchases::reverse()`.

- **`billing:install`** publishes the config and generates a migration that adds the tier column and the
  Cashier customer columns to your owner model's own table — the columns no package migration can create
  without knowing which table they belong to. Before this, a fresh install rendered "Free" while every
  plan-sync webhook died at "column not found".

- A **default `TierResolver` binding** (`ColumnTierResolver`), so a fresh install resolves a tier without
  any extra wiring; an app that keeps no tier column rebinds to `SubscriptionTierResolver` in one line.

- Domain events are now dispatched through **Laravel's event dispatcher** as well as the package's own
  effect bus, so a host app can `Event::listen` or `Event::fake` a `PaymentSucceeded` /
  `SubscriptionStateChanged`. The package's shipped effects still run either way.

- A populated default **account-hub navigation** (with translations) and an `account.stylesheet` seam so
  the hub ships with working navigation and a documented way to style it.

- **Subscribing — the entrance a billing package needs.** A visitor can now become a paying subscriber
  from the plan screen: the client submits the tier key (never a price), and the package opens a hosted
  checkout in subscription mode. The trial, the provider's tax and VAT-id collection, promotion codes and
  the billing address all ride on that one session, and the card — with SCA / 3-D Secure — is captured on
  the provider's own page. On return, the subscription is reconciled onto the local row on the spot, so a
  paying customer is never shown "Free" while the webhook is still in flight. An owner who already
  subscribes swaps in-app instead of opening a second subscription (which would double-bill them). The
  checkout return URLs default to the hub's own routes, so a fresh install can take a payment without any
  URL configuration.

- Per-tier `legacy_prices`: retired provider price ids that still resolve to a tier. Rotating a price in
  the provider no longer strands the subscribers still on the old price — they keep the tier they pay
  for — while a new subscription is always sold at the current `provider_price`.

- Usage-based billing, end to end: recorded usage is now reported to the provider that bills it, so a
  product charging "19 EUR a month plus 0.50 EUR per 1 000 emails, first 10 000 included" is expressible
  in full. A scheduled `billing:usage:flush` folds each cycle's usage for an owner and meter into a
  single report and hands it to the provider (Stripe's billing meters, via the new provider-neutral
  `UsageReporter`).
  - **A retry cannot double-bill.** The identifier is minted when the usage is recorded and replayed
    unchanged on every retry, so the provider recognizes it and bills the usage once, no matter how many
    times the network made us ask. An already-attempted report is never folded into a new one. Note that
    Stripe answers a replayed identifier by REJECTING it (`duplicate_meter_event`) rather than accepting
    it quietly; that rejection means the usage is already billed, and is treated as the success it is.
  - **An outage cannot lose revenue.** Reports retry with exponential backoff; usage that genuinely
    cannot be reported is marked failed and logged as an error, because it is money that will not be
    collected unless someone acts. It is never dropped quietly.
  - **A metered tier cannot run on a driver that cannot meter.** The app refuses to boot instead — the
    alternative is counting every unit, reporting none, and invoicing the base fee alone, which nothing
    would flag until the month's revenue came in short.

- Usage-billed tiers: a tier can now declare what it charges for USAGE on top of its base fee —
  "19 EUR a month, plus 0.50 EUR per 1 000 emails, first 10 000 included" — under
  `config('billing.tiers.<tier>.metered')`. An app records usage through a single call
  (`UsageRecorder::record($owner, 'emails', 42_000)`), which moves the owner's counter and writes the
  outbox row the provider is billed from in one local write, so the number the owner sees on the usage
  screen and the number they are charged for come from the same place. Usage carries an idempotency key,
  so a send job that runs twice is billed once, and it is accounted into the moment it HAPPENED, so a
  late record still lands in the cycle it belongs to. Malformed metering config throws rather than
  quietly billing nothing.

- Usage is accounted into the SUBSCRIPTION's billing cycle, not the calendar month: an owner who renews
  on the 17th has neither a calendar month nor a clean month boundary, and bucketing their usage by one
  would bill part of it into a cycle the provider has already invoiced. The cycle is mirrored from the
  provider onto the local subscription row.

- The default usage provider now reads the package's own counters, so an app that meters through the
  package gets a working usage screen with no extra wiring. An app that meters nothing keeps the
  unmetered provider, exactly as before.

- Usage counters are per METER, so an owner metering two things (emails sent AND contacts stored) no
  longer has both share one budget, where each would enforce the other's limit.

- Live Stripe smoke suite (`composer test:stripe-live`): the Stripe driver's setup-intent, stored
  mandate, payment-method list/default/remove, off-session charge and refund path run against the real
  Stripe test API (not the fake), proving the fake matches Stripe. It skips without a
  `STRIPE_TEST_SECRET` and is outside the default gate, so a bare checkout and CI stay offline.

- Real-browser account-hub E2E (`composer test:browser`, Playwright/Chromium): full-page rendering and
  a Livewire round-trip through the hub screens.

- DATEV export: a `DatevExport` that writes invoices as a DATEV "Buchungsstapel" (EXTF) file — the
  31-field header, the column captions, and one revenue booking per invoice (gross amount, debit
  marker, the configured receivables/revenue accounts, document date and number). The account numbers
  and length are read from `config('billing.datev')` and, being chart-of-accounts specific, are meant
  to be confirmed with the tax advisor; left empty the file is still structurally valid.

- E-invoicing (EN 16931 / XRechnung): a dependency-free `EInvoice` writer that renders a stored
  invoice as a UBL 2.1 document with the mandatory business terms — customization id, number, issue
  date, type code 380, currency, seller and buyer parties with postal addresses and VAT schemes, the
  per-rate tax breakdown, the document totals, and one line per item. The seller is the platform
  (`config('billing.company')`); the buyer, line items and tax split are stored on the immutable
  invoice. ZUGFeRD (embedding this XML in a PDF/A-3) is a separate opt-in writer.

- Admin/support console core: a `BillingAdmin` service for the three out-of-band operations a support
  agent performs on an owner's billing — comp a tier, cancel immediately, refund a charge — each
  recorded on the billing audit ledger, plus a reader for an owner's audit trail. It carries no UI and
  no authorization of its own, so an app wires it into its own admin panel behind its own gate.

- Seat sync: a `SeatSync` contract with a Stripe default that keeps a team owner's subscription
  quantity in step with its seat count. It acts only on an owner that provides seats and has a live
  subscription, and is a safe no-op otherwise (no seats, no subscription, or one Stripe has already
  canceled) — seat sync must never break a team's account.

- Licensing gate, separate from pricing: a `License` contract (with a config-backed default reading
  `config/license.php`) that answers what a tier UNLOCKS — boolean feature grants and numeric limits
  — independently of what it costs. Fail-closed (an unlisted feature is denied; an unlisted or null
  limit is uncapped) and stateless, so there is no cached grant to purge when a tier changes.

- `billing:run` scheduler command that advances the active driver's recurring billing cycle
  (scheduled hourly). A no-op under Stripe, which drives its own cycle; the seam exists so a
  local-engine driver advances every due subscription without a rewrite. Honours the master switch.

- Per-surface suspension lockout: a `billing.suspend:<surface>` middleware that returns HTTP 423
  (Locked) once a delinquent owner reaches the surface's configured dunning rung
  (`config('billing.suspension')`). The delinquency clock is a stored timestamp (`delinquent_since`,
  started when a subscription first blocks and cleared on recovery) — never a live gateway status —
  so lockout keeps working during a provider outage. Different surfaces can be withdrawn at
  different stages of delinquency.

- App-shell billing banner: a `<x-billing::banner />` component that surfaces the one thing an owner
  needs to act on — a failed payment, a lapsing grace period, or a trial about to end — with a
  severity-conveying callout and a call to action to the right hub screen, and renders nothing at all
  for a healthy account. New `config('billing.trial.ending_within_days')`.

- Scoped Content-Security-Policy for the account hub: a per-driver policy that whitelists the
  active payment provider's origins (Stripe.js, its frames and API) on the billing screens only,
  never across the rest of the app. Self-only and Livewire/Alpine-safe by default, extensible via
  `config('account.csp.additional')`, and it never overrides a CSP the host already set.

- Master switch now also drops Cashier's own routes (`Cashier::ignoreRoutes()`) when
  `billing.enabled` is off, so a disabled install exposes no billing routes at all.

- Stripe payment rails: on-session charge, stored-mandate creation, payment-method
  tokenization, off-session (merchant-initiated) charge, and refunds — all returning
  provider-neutral value objects.

- Stripe payment-method management (setup intent, list with default first, set-default,
  remove), invoice history and ownership-checked PDF download, and a null-tolerant
  next-invoice preview.

- In-app subscription actions: cancel at period end, resume, cancel now, and an
  upgrade/downgrade swap that resolves the price from the plan catalog by tier key
  (never a client-supplied price) with optional proration.

- One-time add-on purchase: opens a hosted checkout for an add-on (price resolved from
  the add-on key, never the client) and stamps the key on the session so the completion
  webhook credits the owner exactly once — completing the add-on money loop. New
  `billing.checkout.success_url` / `cancel_url` config.

- Account hub (Livewire) — the overview landing: the config-driven navigation to the hub
  sections and a one-line summary of the owner's current tier.

- Account hub (Livewire) — the subscription screen: shows the canonical subscription state
  and a best-effort next-invoice preview, and lets the owner cancel into the grace period
  or resume. Config-driven routes/middleware/layout (`config/account.php`), gated on the
  billing master switch, with a self-contained Basic-Blade view set and informal i18n.

- Account hub (Livewire) — the change-plan screen: the in-app upgrade/downgrade that offers
  the plans purchasable from the current tier and swaps to the chosen one by its key (never
  a client-supplied price). Each option can be previewed before committing — the proration
  strategy reports the net amount due for a mid-cycle change (via Stripe's invoice preview),
  degrading to "no estimate" when the change cannot be previewed rather than showing a wrong
  figure.

- Account hub — the invoice-history screen: lists recent invoices and streams a single
  invoice's document only after the driver confirms the owner owns it (no cross-owner leak).

- Account hub — the payment-methods screen: lists stored methods (default first), sets a
  new default, removes a method, and opens the add-a-method flow with the driver-shaped
  setup payload.

- Account hub — the usage screen: the owner's current metered usage per dimension (read
  from the project's usage provider), with warning/over states, or a plain unmetered note
  when the tier has no limits.

- Account hub — the payment-recovery screen: when a payment has failed (past due) it
  guides the owner to fix their payment method so the provider can retry; otherwise it
  reports nothing to recover.

- Account hub — the danger zone: stops billing immediately (the hook for an app's
  account-deletion flow) behind an explicit two-step confirmation.

- Hosted-portal bridge: a controller that redirects the owner to the provider's own
  billing portal (Stripe's customer portal), or 404s so the app falls back to the in-app
  screens when no portal is available.

- Eligibility gate: money-initiating account-hub actions (add/change payment method,
  swap plan) and the add-on checkout run a `CanTransactMoney` check first — at the UI and,
  as defence in depth, in the money-moving driver itself. The package allows everyone by
  default; an app binds the fail-closed gate with its own age/KYC checks to deny until
  eligible.

- Stripe webhook signature verification and an event mapper that translates Stripe
  events (subscription lifecycle, invoice paid/failed, one-time checkout) into the
  package's provider-neutral domain events.

- The Stripe driver is wired end to end: the SDK client, the driver, the account-hub
  and webhook contracts, the customer directory, and the default webhook effect set
  (plan sync, add-on credit, dunning) are registered so a Stripe app works out of the
  box. In production the app refuses to boot without a webhook signing secret.

### Changed

- Two concurrent first webhook deliveries for the same new subscription now converge instead of
  answering the provider with a 500. The losing insert's unique violation reruns the sync against the
  now-existing row under the same out-of-order guard, so the provider sees a clean success rather than
  an error it reads as our outage.

- The Stripe driver now reports `supportsMeteredNative: false`. It previously advertised native
  metering while nothing in the package reported usage to the provider, so an app trusting the flag
  would have billed no usage at all. A capability states what the package delivers, not what the
  provider is capable of.

- Next-invoice preview: the upcoming-invoice preview asked Stripe to preview a customer without
  saying WHAT to preview, which Stripe rejects — so the preview silently degraded to "no estimate"
  for every customer, always. It now previews against the owner's subscription (and returns null when
  there is none, which is correct). Found by running the driver against the real Stripe test API; the
  faked suite had stubbed a success response for a call Stripe never accepts.

- Account hub: the full-page screens now render inside the configured layout
  (`config('account.layout')`). They previously mounted a bare Livewire view with no layout, which
  failed full-page with "No hint path defined for [layouts]" — only surfaced by a real-browser test,
  not the component tests.

- E-invoice (EN 16931): a zero-rated line is now VAT category "Z" instead of "S" with a 0% rate
  (BR-S-05/06); the document-level tax total is derived from the per-band sum so it always reconciles
  (BR-CO-14) and the totals stay consistent (BR-CO-13/15); the buyer reference (BT-10) and the party
  electronic addresses (BT-34/49 EndpointID) are now emitted.

- DATEV export: a credit note (an invoice that credits another) is now booked as "H" (Haben), not
  "S" — booking it as a sale overstated turnover.

- Admin refund now passes an idempotency key, so a double-click or retry cannot double-refund.

- Subscription plan-sync: a subscription event with no timestamp can no longer resurrect access over a
  delinquent owner or disable the out-of-order guard for later events.

- The account-hub CSP now sets `frame-ancestors 'self'`, so the money-moving hub cannot be framed
  (clickjacking).

### Fixed

- Recording usage no longer eats an unrelated request's in-flight reservation. `UsageMeter::commit()`
  decremented the `reserved` counter unconditionally, so a plain `record()` — which never reserved
  anything — silently destroyed a hold another request was relying on, which meant reserving and recording
  could not be used in the same application. Recording now only moves what it used.

- The quota gate now counts HELD units against the allowance, not just used ones — a gate blind to
  reservations would wave through exactly the request a reservation exists to refuse.

- Adding a card or buying an add-on no longer creates an ANONYMOUS Stripe customer. Both paths created the
  customer themselves, with no email, no name and no back-reference to the owner — and nothing ever
  re-stamped it, so if either was the account's first trip to the provider, every invoice and receipt that
  account ever got was anonymous. Both now go through the customer registry, which creates the customer
  with the owner's identity on it. This was live on the default configuration.

- `billing.customer.column` is now honoured everywhere, not just in half the package. Invoice history, the
  next-invoice preview, the plan-swap preview and the payment-method manager all read a hardcoded
  `stripe_id`, so on a renamed column they silently showed nothing — an empty invoice list, no cards, no
  preview, with no error to notice — and `billing:cards:warn` reported "Warned 0 owner(s)" and exited
  successfully while warning nobody. Worse, adding a card created a REAL customer at Stripe and then failed
  to write the id to a column that did not exist: a 500, and a live orphaned Stripe customer left behind on
  every retry. `billing:install` now generates the migration for the columns you configured, too, rather
  than for the default names.

- Reconciling a subscription from the provider no longer downgrades a paying customer because of a
  checkout they abandoned. The reconcile asked Stripe for the customer's single most recent subscription
  and took it — but Stripe lists newest first, and an abandoned checkout leaves an `incomplete_expired`
  subscription behind that is NEWER than the one the customer actually pays on. The lapsed one won, and a
  state that grants no access pulls the tier to zero: the customer kept paying, on the free tier, until
  something else moved their subscription. Both the post-checkout return and `billing:sync` now read every
  subscription the customer has and reconcile the one that is actually alive.

- A customer who pauses billing no longer keeps their paid plan for free. Stripe does not change a
  subscription's status when collection is paused — it keeps reporting `active` — and the package read
  only the status, so a pause taken in the hosted customer portal (which the package links from its own
  navigation) left the owner on the paid tier while Stripe raised no further invoice. Indefinitely. The
  package now reads `pause_collection`, maps it to a new `SubscriptionState::Paused`, takes the paid tier
  away, and shows the owner a banner explaining why their features stopped and how to resume. A pause is
  never treated as delinquency: it starts no dunning clock, sends no suspension warning and charges no
  late fee — the owner chose it, they did not fail to pay.

- A dunning notice can no longer be lost forever. The webhook spine recorded an effect as done _before_
  running it, so an effect that then failed left a marker saying "handled" and a customer who was never
  told their payment failed — and nothing would ever come back for it. An effect now claims, runs and
  marks itself handled inside one transaction: if it fails, the claim rolls back with it and the work
  stays owed. Notifications are queued after that commit, so a run that rolled back cannot have mailed
  anybody, and the retry that redoes the work is not a second mail to the customer.

- A payment failure that the provider retries no longer mails the customer once per retry. Stripe mints a
  fresh event id for every retry of the same failing invoice, and the dunning notice deduplicated on that
  event id. It now deduplicates on the invoice.

- A payment that needs 3-D Secure authentication, or a SEPA debit still processing, is no longer
  reported as a decline. `ChargeResult` now distinguishes settled, declined, requires-action (carrying
  the client secret the front end confirms against) and pending — so a successful European card payment
  is no longer indistinguishable from a failure, and dunning no longer acts on a payment that simply is
  not finished yet.

- The payment-recovery screen now handles an incomplete (awaiting-confirmation) subscription: it prompts
  the owner to confirm the payment, where it used to answer "nothing to recover" to the very owner the
  banner was telling to confirm their payment.

- One account can no longer remove or re-default another account's stored payment method. The method id
  travels to the browser to be rendered and comes back under the client's control; both mutating verbs
  now check it against the owner's own methods (and the driver re-checks it against the Stripe customer),
  where before a detach — which is global to the method id — went through unchecked.

- A paying owner whose provider price was rotated is no longer parked on the free tier after a single
  past-due blip. The blip pulled the tier to zero and every later event carried a now-unrecognized price,
  so nothing ever restored the paid tier; the sync now falls back to the last tier resolved on the local
  subscription row.

- A subscription carrying more than one price item no longer corrupts the account. Every subscription
  surface addressed the item at position 0 — but a subscription may legitimately carry a second item (a
  usage-billed component, an item the app added), and the provider does not promise their order. As a
  result: the tier lookup could resolve to nothing and **force a paying customer down to the free tier
  on every subscription webhook**; a plan swap could reprice the wrong item; and a seat sync could write
  a quantity onto an item that forbids one. The tier item is now identified by its price, and any item
  the package did not put there is left strictly alone.

- The tier is only pulled to zero when the subscription actually stops granting access. An
  access-granting subscription whose price maps to no configured tier now leaves the owner's tier
  untouched — unknown is not zero, and the owner is paying.

- Seat sync no longer swallows a provider rejection. A failed sync is logged instead of silently
  leaving the team billed for the seat count the provider still holds.

- Off-session (merchant-initiated) charges now include the Stripe customer, so a
  stored payment method can actually be charged when the cardholder is away.

- Plan sync ignores an out-of-order or retried older subscription webhook instead of
  regressing a paying customer's tier, and records the subscription state locally.

- One-time add-on credit waits for the payment to actually settle (asynchronous
  methods no longer credit while still pending).

- Canceling or resuming a subscription is a safe no-op when it is already gone at the
  provider, so account deletion is never blocked.

- Completed the zero-decimal currency set (UGX, XPF, …) so those amounts are no longer
  off by 100×.

- The webhook endpoint honours the billing master switch (404 when billing is disabled).

- Payment-method listing requests the full page so the default card is never truncated
  away.

- Charge, off-session charge and refund accept an idempotency key (passed to the
  provider), so a retried money-moving operation cannot double-charge or double-refund.

- A trialing or grace-period subscriber synced from a webhook resolves to their paid
  tier instead of being dropped to the free tier.

- One subscription-state row per owner is enforced, and same-second out-of-order
  webhooks can no longer restore access to a canceled subscription.
