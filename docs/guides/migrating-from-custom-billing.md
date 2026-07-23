# Migrating from your own billing code

Already have a hand-rolled billing namespace — your own subscription model, webhook controller, invoice
logic? Adopt the package in place, one seam at a time, with no big-bang cutover:

1. **Install alongside your code.** Run the [installer](../single-seller/installation.md). The package's own
   tables (`billing_subscriptions`, `billing_invoices`, `billing_usage_counters`, …) are prefixed, so they sit
   next to yours without colliding — nothing of yours is touched yet.
2. **Point it at your billable.** Set `billing.owner` (the actor that owns billing — the user, or its team)
   and `BILLING_CUSTOMER_MODEL` to your model. The owner is resolved through the `BillingEntityResolver`
   contract; bind your own implementation if the mapping is non-trivial.
3. **Backfill live state.** Run `php artisan billing:sync` so every existing Stripe subscription lands in the
   local rows immediately — not a year from now (see [Migrating from Cashier](migrating-from-cashier.md)).
4. **Move the webhook.** Point your Stripe endpoint at `billing/webhook` (the `billing.webhook_path`) and
   retire your own controller. The package verifies the signature, dedups redeliveries, and translates each
   event into a neutral domain event + effect — the part hand-rolled billing most often gets subtly wrong.
5. **Swap reads and writes to the contracts.** Replace calls into your billing namespace with the package
   contracts resolved from the container — `SubscriptionActions` (cancel / resume / swap), `Invoices`
   (history + download), `UsageProvider` (current usage). Your controllers and views stop touching your own
   billing models.
6. **Delete the old namespace.** Once nothing resolves it, remove the code, its migrations (the data now lives
   in the package tables), routes and tests. If your old code did **more** than the package (a bespoke report,
   an export), capture that gap before deleting so the capability isn't quietly lost.

Each step is independently shippable, and the package runs beside your code until you retire the last of it.

## How your columns map

The package's tables use **provider-neutral** names, so one schema serves Stripe today and a local-engine driver
later. The renames to expect when you backfill data (step 6) all follow one rule — *no provider or app
assumption in a column name*:

| A hand-rolled table often called… | …becomes | Key column change |
|---|---|---|
| `stripe_events` / a webhook log | `billing_webhook_events` | a single `stripe_event_id` splits into `provider` + `event_id` (an id is only unique *within* a provider) |
| `addon_purchases` / top-ups | `billing_addon_purchases` | the checkout/session id becomes a neutral `reference`; the buyer is a polymorphic `owner_type` / `owner_id`, not a hardcoded `user_id` |
| `subscriptions` | `billing_subscriptions` | `provider` + `provider_id` instead of `stripe_id`; the plan is a neutral `tier_key`; grace/ended dates are local `ends_at` columns, never a provider call |

Map your old rows onto these columns before you delete the old namespace — the data then lives entirely in
the package tables.

---

[← Back to the documentation index](../README.md)
