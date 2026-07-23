# Event reference

The package's own events, all under `Pushery\Billing\Events`. Listen to these and your app never reads a
provider payload.

A driver's webhook mapper translates each provider event — a signed Stripe event, a bare-id ping, an HMAC
batch — into one of the domain events below, and everything downstream listens on those. That is what makes
a side effect survive a driver change: the mapper is provider-specific, the event is not.

The marker interface is `BillingDomainEvent`. It carries no methods; it exists so the effect bus can only be
handed an event that is part of this contract.

## Listening

Every domain event goes through Laravel's dispatcher, so listen the ordinary way:

```php
use Illuminate\Support\Facades\Event;
use Pushery\Billing\Events\PaymentFailed;

Event::listen(PaymentFailed::class, function (PaymentFailed $event): void {
    // $event->customerReference, $event->amount, $event->reference
});
```

They are equally ordinary to assert on:

```php
use Illuminate\Support\Facades\Event;
use Pushery\Billing\Events\SubscriptionStateChanged;

Event::fake();

// ... exercise the code under test ...

Event::assertDispatched(SubscriptionStateChanged::class);
```

See [Testing](../guides/testing.md) for `Billing::fake()`, which fakes the driver rather than the events, and
for the cross-engine suites.

## The events

`customerReference` is always the provider's customer id, resolved back to your billable model through
`billing.customer.column`. Money is always a `Money` value object — an integer minor amount plus a currency,
never a float.

| Event | When it fires | Payload |
| --- | --- | --- |
| `SubscriptionStateChanged` | A subscription moved to a new canonical state | `customerReference`, `state` (a `SubscriptionState`), `subscriptionReference?`, `tierKey?`, `occurredAt?`, `periodStart?`, `periodEnd?`, `trialEnd?` |
| `TrialEnding` | A trial is about to end, a few days out | `customerReference`, `subscriptionReference`, `trialEndsAt` |
| `PaymentSucceeded` | A payment completed | `customerReference`, `amount`, `reference` |
| `PaymentFailed` | A payment attempt failed | `customerReference`, `amount`, `reference` |
| `PaymentActionRequired` | The bank asked the cardholder to confirm (3-D Secure) | `customerReference`, `reference` |
| `ChargebackReceived` | A charge was disputed | `customerReference`, `reference`, `amount` |
| `MandateRevoked` | A stored mandate is no longer usable | `customerReference`, `mandateId` |
| `AddonPurchased` | A one-time add-on was bought and paid | `customerReference`, `addonKey`, `amount`, `reference`, `paymentReference?` |
| `AddonRefunded` | A charge was refunded | `paymentReference`, `cumulativeRefunded`, `reason?` |
| `InvoiceFinalized` | The provider finalized an invoice — it now legally exists | `invoice` (an `InvoiceSnapshot`) |
| `InvoiceCorrected` | A correcting document was issued against a finalized invoice | `correction` (an `InvoiceCorrectionSnapshot`) |
| `InvoiceCredited` | **Deprecated** — the former name of `InvoiceCorrected` | `correction` |
| `InvoiceUpcoming` | The provider is about to finalize the next invoice | `customerReference` |
| `SeatQuantityChanged` | A team's billed seat quantity actually moved | `owner`, `from`, `to` |
| `UsageBacklogStalled` | Usage has sat unreported longer than `billing.metering.stall_hours` | `pendingRollups`, `pendingUnits`, `oldestRecordedAt`, `stalledHours` |
| `UsageReconciliationDrift` | Our ledger and the provider's meter disagree | `owner`, `meterKey`, `period`, `reported`, `recorded` |
| `BillableAccountDeleting` | An account is **about to be** deleted | `owner` |
| `AccountBillingUpdated` | An owner's billing state changed — a broadcast, for live refresh | `owner` |
| `AccountToastNotified` | A transient message for the owner — a broadcast | `owner`, `message`, `level` |

A few of these repay a closer look.

**`SubscriptionStateChanged` carries more than the state.** `occurredAt` is the provider event's own
timestamp, which is how a retried or out-of-order delivery is ignored instead of regressing a newer state.
`periodStart` and `periodEnd` ride along because metered usage is accounted into the **subscription's**
cycle, not a calendar month — an owner who renews on the 31st has no calendar month to bill into. Each is
null when the provider conveys no such value, and a null `tierKey` means the change conveys no tier.

**`AddonRefunded` carries the cumulative total, not the delta.** The add-on ledger claws back only the part
it has not already reversed, so two partial refunds and a redelivery each do the right thing. It is keyed on
`paymentReference` — the payment id, not the checkout session — because that is what a refund event carries.
A refund of anything that is not a tracked add-on matches no purchase and reverses nothing.

**`BillableAccountDeleting` is present-continuous on purpose.** The listener runs while the owner still
exists, so the live subscription can be canceled at the provider before the row is gone. The package's own
eraser dispatches it; an app with its own delete flow dispatches it itself, after re-confirming identity and
before deleting the model. Skip it and a deleted account lingers as an active, still-charging subscription.

**`UsageBacklogStalled` is the alarm a successful exit code cannot raise.** The flusher exits successfully
during a provider outage, because a growing backlog is not a crash — but a backlog that never drains is lost
revenue, since past the provider's acceptance window the usage is not retro-billed at all.

## Deprecation aliases

`InvoiceCredited` was renamed to `InvoiceCorrected`: the old name conflated a correcting document with a
self-billing credit note, which is a different document with a different type code.

For one deprecation window the old class still fires. `InvoiceCorrected` implements `HasDeprecatedAlias`, and
the effect bus dispatches the alias through Laravel's dispatcher **alongside** the event — so an existing
`Event::listen(InvoiceCredited::class)` keeps being called instead of going quiet, which is the worst outcome
of a rename. The alias reaches host listeners only and is never re-run through the package's own effects, so
nothing is persisted twice.

Migrate to `InvoiceCorrected` and read `$event->correction`. The old class and the alias firing go in a later
release.

## The shipped effects

An effect is an invokable class registered against a domain event, and each runs in **its own** queued job —
so a slow or failing effect can neither hold the provider's request open nor take its siblings down with it.
Idempotency is per effect, not per delivery, so replaying a delivery whose third effect failed re-runs only
that one.

| Event | Effect | What it does |
| --- | --- | --- |
| `SubscriptionStateChanged` | `SyncPlanFromSubscription` | Writes the local subscription row and the owner's tier column |
| `SubscriptionStateChanged` | `SendSubscriptionActivatedNotice` | Tells the owner the subscription is live, once per subscription |
| `SubscriptionStateChanged` | `SendSubscriptionCanceledNotice` | Tells the owner when access ends, keyed on the grace state |
| `PaymentSucceeded` | `SendPaymentReceipt` | Tells the owner their money moved |
| `PaymentFailed` | `SendDunningNotice` | Starts or advances the dunning conversation |
| `PaymentActionRequired` | `SendPaymentActionRequiredNotice` | Nudges the owner to confirm at their bank |
| `AddonPurchased` | `CreditAddonPurchase` | Applies the credit or the prepaid units, exactly once per purchase |
| `AddonRefunded` | `ReverseAddonPurchase` | Claws back the unspent part of the purchase |
| `InvoiceFinalized` | `PersistInvoice` | Writes the immutable invoice record the e-invoice and DATEV exports render from |
| `InvoiceCorrected` | `PersistInvoiceCorrection` | Writes the correction row, linked to the invoice it corrects |
| `InvoiceUpcoming` | `FlushUpcomingUsage` | Force-flushes the usage outbox so a closing cycle is billed in that cycle |
| `MandateRevoked` | `RevokeMandate` | Drops the stored mandate so nothing is charged against it |
| `TrialEnding` | `SendTrialEndingNotice` | Reminds the owner before the first charge |

Register your own the same way the shipped ones are registered, against the event rather than against a
provider string:

```php
use Pushery\Billing\Events\PaymentSucceeded;
use Pushery\Billing\Webhooks\WebhookEffectRegistry;

app(WebhookEffectRegistry::class)->on(PaymentSucceeded::class, NotifyAccounting::class);
```

Events with no shipped effect — `ChargebackReceived`, `SeatQuantityChanged`, `UsageBacklogStalled`,
`UsageReconciliationDrift` — are dispatched for your app to act on. A chargeback and a reconciliation drift
in particular are decisions the package will not make for you.

## Broadcast events

`AccountBillingUpdated` and `AccountToastNotified` are broadcast on the owner's private channel so the
account-hub screens refresh live. Both are a no-op unless `billing.realtime.enabled` is on **and** a
broadcaster is configured; without that the screens fall back to a bounded poll.

`AccountBillingUpdated` deliberately carries no payload — the client re-fetches — so nothing sensitive is on
the wire.

---

[← Back to the documentation index](../README.md)
