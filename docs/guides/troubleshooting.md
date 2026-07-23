# Troubleshooting

Every exception the package throws, what it means and what to do about it — plus the failures that throw
nothing at all, which are the ones that cost money.

The package is deliberately fail-closed. Where a misconfiguration would otherwise degrade into something
that looks like it works — usage counted and never billed, VAT computed and never charged, a webhook
accepted without a signature — it refuses instead. That is why several of these fire at boot rather than on
the request that would have gone wrong.

## The app refuses to boot

Six guards run in the service provider's `boot()`, all of them behind the `billing.enabled` master switch:
turn billing off and none of them can stop your app starting.

### `MeteringUnsupported`

> Tier metering is configured (meter '…'), but the active billing driver '…' cannot report usage.

A tier bills for usage on a driver that has no way to report it. The degraded alternative is the worst one
available: every unit counted, none reported, and an invoice for the base fee alone — nothing looks broken
until the month's revenue comes in short.

Either move to a driver that reports usage, or remove the `metered` components from the tier.

### `TaxModeUnsupported`

Three shapes, one theme: the invoice would go out with no tax and nothing would surface it until the VAT
return did not add up.

- `billing.tax` is `provider`, but the active driver computes no provider tax.
- `billing.tax` is a local mode such as `eu_oss`, but the active driver defers tax to the provider — the VAT
  would be computed locally and never charged.
- `billing.tax` is not a resolvable mode at all: a typo (`eu_os`), or the key turned into an array by adding
  a sub-key underneath it.

Set `billing.tax` to a mode the driver can actually apply, or to `none`.

### `InvalidBillingConfig`

The configuration contradicts itself in a way that would fail silently at runtime:

| Message | Cause |
| --- | --- |
| `billing.owner must be 'user' or 'team'` | Any other value |
| `billing.zero_tier is '…', but no tier with that key is defined` | The fail-safe tier does not exist, so a fallback would land nowhere |
| `billing.untouchable_tiers lists '…', but no tier with that key is defined` | A protected tier key that does not exist protects nothing |
| `Tier '…' references dimension '…', which is not defined` | The usage screen would render a dimension nothing feeds |
| `The price currency '…' is not a valid ISO 4217 code` | Not three uppercase letters |
| `Dimension '…' warn_threshold must be between 0 and 1` | A threshold outside that range never warns, or always does |
| `The dunning ladder's after_days must strictly ascend` | Rungs out of order never escalate |

### `RetentionBelowStatutoryMinimum`

> billing.retention.erased_financial_days is … days, below the ~10-year statutory floor …

An erased owner's retained invoices would be pruned before the law allows. Keeping data **longer** is always
fine — only shortening it below the floor is refused, and only until someone opts in on purpose for a
jurisdiction whose minimum genuinely is shorter. Raise the window, or set
`billing.retention.allow_below_statutory_minimum` deliberately.

### `CustodyModeNotPermitted`

`billing.marketplace.custody.platform_held` is on, which means the platform itself would hold other people's
funds. That is a regulated activity in most jurisdictions, so a configuration flag alone is never enough:
bind an implementation of `Pushery\Billing\Contracts\PaymentServiceLicenseAttestation` to declare in code
that you hold the license, or turn the flag off.

### `WebhookSigningNotConfigured`

> Webhook signature verification for the 'stripe' driver is not configured.

**Production only.** Outside production the guard is silent, so this is a deployment failure, not a local
one. Set the driver's signing secret — for the Stripe driver, `STRIPE_WEBHOOK_SECRET`, which Cashier reads
into `cashier.webhook.secret`. Booting without it would mean accepting unverified webhooks, and a webhook is
how money and entitlements move.

## Runtime errors

### `BillingDisabled`

> Billing is disabled; cannot charge.

`billing.enabled` is `false`, so the manager resolved the `NullDriver` — and something asked it to move
money anyway. Reading is fine while billing is off; charging, refunding, tokenizing a payment method and
creating a mandate are not. Guard the call site, or turn billing on.

### `UnsupportedDriver`

`billing.default` names a driver nothing registered. Check the spelling, and that the driver's service
provider is installed.

### `EligibilityDenied`

> The owner is not eligible to transact; money movement was refused.

The `CanTransactMoney` gate denied the owner before a charge, subscribe, checkout or add-on purchase. The
gate is fail-closed: it denies unless the owner is **positively** eligible, so an unbound or
not-yet-answering implementation denies rather than lets money move on an unanswered question. Bind your own
implementation if you have age or identity requirements; if you do not, make sure the one you bound returns
true for an ordinary owner.

### `QuotaExceeded`

> Quota exceeded on meter '…': N requested, M remaining in the allowance.

A metered request would take the owner past a **blocking** allowance. A degrading or fair-use meter never
raises this — those keep serving and are only flagged. The exception carries `meterKey`, `policy` and
`remaining`, so catch it and render "you have M left" rather than a bare status code. The
`billing.quota:<meter>` middleware turns it into `billing.quota.status` (`429` by default) for you.

### `SeatDowngradeBelowOccupied`

The seat quantity would be set below the number of seats actually occupied, which would bill for fewer seats
than are in use. Remove members first, or bill the higher number.

### `CouponUnavailable`

Four reasons, all recoverable and all worth showing the customer verbatim: the coupon is not active, it has
expired, it has reached its redemption limit, or this account has already redeemed it. Catch it at the
redemption call site and surface the message.

### `CurrencyMismatch`

> Cannot operate on Money of different currencies: … vs … .

Two `Money` values in different currencies met in an arithmetic operation. This is a programming error
rather than a configuration one: the value object refuses instead of producing a number whose currency is a
guess. It also fires when a tier's catalog price is in a different currency from the subscription line it
would price.

### `CycleAmountUnresolvable`

A subscription line cannot be priced for its cycle. Four causes:

- a fixed line carrying no amount — only a metered line may be stored unpriced
- a metered line with no resolver named, on the line's `preprocessor` column or bound as a default
- a metered line whose meter has no matching component in the tier catalog
- a metered component with no unit price, on a driver that rates usage locally

It throws rather than returning zero, because a metered line with no usage legitimately costs nothing: a
zero for "I could not work this out" produces an invoice that looks settled while billing nothing.

### `PostureNotPermitted`

Either the resolved seller-of-record posture is not in
`billing.marketplace.seller_of_record.allowed_postures`, or a `seller_of_record` posture was resolved for an
electronically supplied service without the rebuttal being asserted. A platform that sets its own terms,
authorizes billing or approves the supply cannot truthfully assert the rebuttal.

## Tax and invoicing

### `UnknownTaxCountry`

> The tax country code '…' is not an assigned ISO 3166-1 alpha-2 country code.

Pass a two-letter code (`DE`, `US`). It is refused rather than zero-rated because a zero here is
indistinguishable from a legitimate supply outside the EU VAT area, and would under-declare VAT silently.

Worth knowing what this cannot catch: a typo that lands on **another** assigned country (`DE` mistyped as
`DK`) is indistinguishable from a deliberate supply to that country.

### `InvalidInvoiceCorrection`

- An amendment must reference the invoice it corrects. A correction with no origin reference is only valid
  as a cancellation.
- A correction carries positive magnitudes. The document's nature inverts the meaning, not the sign, so pass
  the absolute amount being corrected.

Both are thrown when the snapshot is constructed, so a malformed correction never reaches persistence or an
e-invoice writer.

### `DatevTransactionUnresolvable`

> No DATEV account is configured for the '…' transaction.

Configure the account under `billing.datev.accounts` for the active chart. The export aborts rather than
booking to a default account, because a posting on the wrong account imports cleanly and surfaces only when
an auditor reads it.

### `PdfRendererUnavailable` and `MissingPdfEmbedder`

The package renders invoice **HTML** itself and leaves the PDF step to you, because a PDF toolchain is a
heavy, opinionated dependency a lean package should not force on every install.

- `PdfRendererUnavailable` — a PDF was requested and no `PdfRenderer` is bound. Bind one, or use the HTML.
- `MissingPdfEmbedder` — a hybrid ZUGFeRD PDF/A-3 was requested without the optional embedding toolchain.
  Install it, or use the CII XML directly.

## Nothing threw, and something is still wrong

These are the expensive ones, because the app looks healthy.

**A paying customer still sees the free tier.** `billing.customer.model` is not set, so no subscription
webhook can resolve its owner and the local row is never written. Set it, then run `billing:sync` to backfill
what the webhooks could not apply.

**Usage is recorded and never billed.** The meter does not exist at the provider, or was archived there.
Usage reported into a meter that does not exist fails silently and surfaces, if ever, as an under-charged
invoice a month later. Run `billing:meters:check`, which exits non-zero on a missing meter and fits a deploy
check. Then `billing:usage:reconcile --redrive` returns the rollups that gave up to pending.

**Usage stopped flowing after an outage.** The flusher exits successfully during a provider outage on
purpose — a growing backlog is not a crash. Watch for the `UsageBacklogStalled` event, which fires once the
oldest pending rollup is older than `billing.metering.stall_hours`, and for `UsageReconciliationDrift`, which
fires when the local ledger and the provider's meter disagree.

**Webhooks arrive but nothing happens.** Check the delivery rows: an effect that failed all its retries is
marked failed and stays re-driveable. `billing:webhooks:replay --failed` runs it again long after the
provider stopped redelivering. Idempotency is per effect, so a replay re-runs only the effect that failed.

**The account hub 404s.** Either `billing.enabled` is off — the routes are not registered at all — or
Livewire is not installed. The hub is optional and Livewire is a suggested dependency, not a hard one; the
billing core does not need it.

**The admin console denies everyone.** `billing.admin.ability` names a Gate ability **your app** defines.
Until it is defined the Gate denies, which is deliberate: the console is never open by accident.

**A cancellation at the provider did not stop billing here, or the other way round.** Dispatch
`BillableAccountDeleting` before you delete a billable model. The listener runs while the owner still
exists, so the live subscription can be canceled at the provider before the row is gone.

**Everything is fine locally and broken in production.** The webhook-secret guard is production-only, and
tax and metering guards depend on the driver that is actually active. Run with the production driver and
`APP_ENV=production` once before deploying.

---

[← Back to the documentation index](../README.md)
