# Admin and support

## Audit trail

Every money movement and entitlement change is recorded on an append-only audit ledger with WHO did it — the
specific actor and its category (customer / admin / webhook / system), not just what happened. So "why is this
customer on Free?" has an answer: the plan was revoked by a past-due webhook, or canceled by a support agent,
or by the customer themselves.

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
on its own retention window (`billing.retention.audit_days`, default 10 years — the bookkeeping window; weigh
it against GDPR storage limitation for your case), and `billing:export` includes an owner's audit history in
their subject-access export.

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
a single billing currency; a free tier (no `price_display`) contributes nothing. Pass a window to
`compute($days)` to measure churn over a different span.

## Admin console (optional Livewire UI)

Those metrics, the recent audit log and the comp-a-tier action are also surfaced as an **optional,
publishable Livewire console** — the UI counterpart of `BillingMetricsReporter` and `BillingAdmin`, for when
support wants a screen instead of the CLI. It mounts under `config('billing.admin.prefix')` (default
`admin/billing`) behind `config('billing.admin.middleware')` (default `web` + `auth`), is plain
framework-agnostic Blade (publish `billing-views` to reskin it), and — like the account hub — renders only
when Livewire is installed, so the billing core stays UI-free.

It is **admin-gated, fail-closed**: every request (mount, render *and* the comp action) is authorized against
the `config('billing.admin.ability')` Gate (default `billing-admin`) — an ability **your app defines**. Until
you define it the Gate denies everyone, so the console is never open by accident:

```php
// A support agent, not the account's own user. Define the ability your app already uses for staff.
Gate::define('billing-admin', fn ($user) => $user->is_staff);
```

For the CLI form of a support comp, see `billing:tier:grant` in the [Command reference](../reference/commands.md).

---

[← Back to the documentation index](../README.md)
