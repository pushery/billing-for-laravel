# Upgrading

What each released version asks of you, newest first. Most ask for nothing beyond running the migrations.

## Before 1.0, a minor may break

The package is pre-1.0, so a **minor** version is allowed to change a published API. Every such change is
listed in the [CHANGELOG](../../CHANGELOG.md) under a heading that says so, and repeated here with what to do
about it. Pin a minor if you want no surprises:

```json
"pushery/billing-for-laravel": "~0.9.0"
```

Every upgrade ends the same way, because a minor may add tables or columns:

```bash
composer update pushery/billing-for-laravel
php artisan migrate
```

## 0.9.0

**Nothing to do.** Documentation only: the pages that described unshipped features are gone, and the
configuration, database, event and troubleshooting references are written from the code. No code, config or
schema changed.

One key that the boot guard already read is now declared in the published config —
`billing.retention.allow_below_statutory_minimum`, default `false`. Behavior is unchanged; it was previously
discoverable only from the exception message. Re-publish the config to pick up the declaration, or ignore it:
the package merges its own defaults underneath yours.

## 0.8.0

**If you mapped `Money` to a decimal-string amount shape**, that pair of methods is gone. It had no shipped
consumer, so it was removed before anything could depend on it. Use `Money::toDecimal()` and
`Money::fromDecimal()`, which are unchanged.

Everything else: nothing to do. Amounts remain integer minor units end to end.

## 0.7.0

**If you ship your own driver implementing `SubscriptionActions`**, add the new optional parameter:

```php
public function cancel(Model $billable, ?CancellationSurvey $survey = null): void
```

Callers are unaffected — the argument defaults to null — and the built-in drivers already have it. A driver
that ignores the survey is a valid driver; the parameter only has to exist so the contract is satisfied.

Run the migrations: 0.7.0 adds the cancellation-survey table.

## 0.6.0

The largest upgrade so far. Three things need attention.

**The cancellation "credit note" is now an "invoice correction".** *Credit note* is reserved for the
self-billing document, which is a different document with a different type code. Rename your references:

| Removed | Use instead |
| --- | --- |
| `ValueObjects\CreditNoteSnapshot` | `ValueObjects\InvoiceCorrectionSnapshot` |
| `Events\InvoiceCredited` | `Events\InvoiceCorrected`, reading `$event->correction` (was `$event->creditNote`) |
| `Webhooks\Effects\PersistCreditNote` | `Webhooks\Effects\PersistInvoiceCorrection` |
| `InvoiceRecord::isCreditNote()` | `InvoiceRecord::isCorrection()` |
| translation key `billing::invoice.credit_note` | `billing::invoice.correction` |

The event is the gentle one: `InvoiceCorrected` also fires `InvoiceCredited` for one deprecation window, so
an existing listener keeps being called rather than going quiet. The value object, effect and model method
are hard renames — a stale reference is a loud "class not found", not a silent no-op. See the
[event reference](../reference/events.md#deprecation-aliases).

**The invoice retention floor dropped from ten years to eight**, and the clock now runs from the end of the
issue year rather than the issue instant. An erased owner's retained invoices become prunable up to two years
earlier than before. That is the point: keeping them the full ten years over-retains personal data past its
obligation. If your jurisdiction requires longer, set `billing.retention.erased_financial_days` higher —
a longer window is always allowed. The separate audit window stays at ten years.

**`InvoiceCorrectionSnapshot` now validates itself.** It refuses a negative amount (a correction carries
positive magnitudes; the document's nature inverts the meaning, not the sign) and refuses an amendment with
no reference to the invoice it corrects. If you construct snapshots yourself, pass absolute amounts.

Also worth knowing, though neither needs action: the documentation moved out of the README into `docs/`, and
several tables were added — run the migrations.

## 0.5.0

**If you keep golden copies of DATEV exports, regenerate them.** The EXTF header's
`Festschreibekennzeichen` was emitted as `0`, marking a booking batch as still alterable after import. It is
now `1`, which changes the bytes of every generated file.

**Your app may now refuse to boot where it previously started.** Two silent failures became loud:

- an unresolvable `billing.tax` — a typo, or the key turned into an array by adding a sub-key under it — now
  raises `TaxModeUnsupported` at boot instead of falling through to "no tax" and issuing every invoice at 0%
- `billing.tax = 'stripe'` is now correctly classified as provider tax, so it is accepted on the driver that
  needs it and refused on one that cannot apply it

**A malformed country code now throws instead of zero-rating.** `EuOssTaxCalculator` treated any code it had
no rate for as zero-rated, so `"DEU"` or an empty string was indistinguishable from a genuine supply outside
the EU VAT area. An unassigned code now raises `UnknownTaxCountry`. Real countries outside the EU VAT area
are still zero-rated. If your data carries three-letter or full-name country codes, normalize them to
ISO 3166-1 alpha-2 before this upgrade.

## 0.4.0 and 0.4.1

**Nothing to do.** 0.4.1 is release-note housekeeping with no functional change.

0.4.0 adds the opt-in `billing.marketplace` config block, off by default, so single-merchant behavior is
unchanged. It also adds a `billing` umbrella publish tag —
`php artisan vendor:publish --tag=billing` now publishes config, migrations, views and translations in one
go, and the specific tags still work.

Contributors only: the static-analysis composer script was renamed to `analyze`.

## 0.3.0

**Nothing to do.** The admin console and the ZUGFeRD PDF/A-3 writer are both additive and both optional.

The hybrid PDF/A-3 needs a real PDF toolchain, so it is a suggested dependency: run
`composer require horstoeko/zugferd` to use it. Without it the method throws `MissingPdfEmbedder` rather than
fataling on an undefined class. The XML writers need none of it.

## 0.2.0

**Check your VAT setup — this release closed two under-charging holes, and both change what customers are
billed.**

- **The EU reverse charge now requires a *validated* VAT id.** Previously any supplied id, verified or not,
  earned the zero-rate. The default `VatIdValidator` proves nothing, so **the zero-rate is not granted until
  you bind a real validator**. Bind `ViesVatIdValidator` if you sell B2B across EU borders; otherwise your
  business customers will now be charged domestic VAT.
- **A domestic B2B sale is no longer zero-rated.** The reverse charge applies only when the buyer's country
  differs from `billing.company.country`. Set that key — when the seller country is unknown, nothing is
  zero-rated, which is the safe direction but probably not what you want.

Also in this release: reverse-charge invoices no longer leak VAT into their totals (an EN 16931 violation a
validator rejects), the EU-OSS table is matched case-insensitively, and a VIES outage is treated as
unavailable rather than invalid. Every provider link-out is now scheme-validated before the redirect.

Run the migrations: 0.2.0 adds the coupon tables and the e-invoicing columns.

## 0.1.1

**If your app deletes accounts from its own flow**, dispatch `BillableAccountDeleting` before you delete the
model:

```php
use Pushery\Billing\Events\BillableAccountDeleting;

event(new BillableAccountDeleting($user));
$user->delete();
```

Without it, a deleted owner stays active and charging at the provider. The package's own eraser dispatches it
for you; a custom delete button does not. Dispatch it after re-confirming identity and before the delete, so
the listener can still resolve the owner's provider reference.

## If you ship your own driver

A driver is a set of contract implementations, so what an upgrade asks of you is exactly which contracts
moved. Across the versions above:

- **0.7.0** — `SubscriptionActions::cancel()` gained `?CancellationSurvey $survey = null`
- **0.6.0** — `PersistCreditNote` became `PersistInvoiceCorrection`, and `InvoiceCredited` became
  `InvoiceCorrected`; a driver's webhook mapper that produced the old event must produce the new one
- **0.5.0** — nothing on the driver contracts, but a driver that reports no provider tax will now be refused
  at boot if `billing.tax` is `provider`, and one that defers tax will be refused on a local mode

Two contracts are worth re-reading after any upgrade because their guarantees are what the fail-closed guards
check: `PaymentRails` (moves money, stores mandates) and `BillingEngine` (the recurring cycle). `PaymentRails`
is deliberately **not** eligibility-gated — the gate belongs at the entry seams where a payment begins, so
that a dunning retry for a subscriber who was eligible when they subscribed is never refused later.

See the [contract reference](../reference/contracts.md) for what each seam guarantees.

---

[← Back to the documentation index](../README.md)
