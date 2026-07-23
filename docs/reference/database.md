# Database reference

Every table the package creates, what it holds, and what an erasure request does to it.

The migrations live under `database/migrations/server` and are loaded by the service provider, so
`php artisan migrate` picks them up without publishing anything. The one migration that **is** generated is
the owner-columns migration `billing:install` writes against your own billable table — see
[the last section](#your-own-table).

## Naming

No package column carries a vendor's name. A row that points at something in the payment provider stores the
provider **key** in `provider` and the remote id in `provider_id`, so the same row shape holds for any
driver. Money is always an integer in the currency's minor unit, in a column ending in `_minor`, beside a
three-letter `currency` — never a float, and never a bare number whose currency you have to infer.

The one vendor-shaped name in the whole design is on **your** table: `billing.customer.column` defaults to
`stripe_id`, because that is the column Cashier already created for apps coming from it. Rename it in config
and the package follows.

## The tables at a glance

`billing:erase {owner}` answers a right-to-erasure request, and every table below has one of five answers.
The eraser and the `billing:export` exporter read the same list, so a table cannot be covered by one and
forgotten by the other.

| Table | What it holds | On erasure |
| --- | --- | --- |
| `billing_subscriptions` | One row per owner and subscription type: the local mirror of the provider's subscription | Purged |
| `billing_subscription_items` | The priced lines of a subscription, for a driver that bills the cycle locally | Cascaded |
| `billing_orders` | The operational billing unit a due cycle is assembled into | Purged |
| `billing_order_items` | The lines of an order | Cascaded |
| `billing_invoices` | The frozen financial document, with its buyer snapshot and lines | Retained |
| `billing_addon_purchases` | A one-time purchase and any reversal of it | Retained |
| `billing_credit_balances` | An owner's money credit, per currency | Purged |
| `billing_prepaid_units` | Usage units an owner bought that never expire | Purged |
| `billing_usage_counters` | The per-period usage total per meter | Purged |
| `billing_usage_events` | The metering outbox: each recorded usage and its reporting state | Purged |
| `billing_usage_reservations` | Short-lived holds on a metered allowance | Purged |
| `billing_cancellation_surveys` | Why an owner canceled, when they chose to say | Purged |
| `billing_coupon_redemptions` | Who redeemed which coupon | Purged |
| `billing_coupons` | The coupon definitions themselves, which belong to nobody | Not owner-scoped |
| `billing_webhook_events` | One row per delivery: the dedup key, the raw payload and the delivery state | Scrubbed |
| `billing_webhook_effect_runs` | One row per effect per delivery, so a replay cannot double-apply | Not owner-scoped |
| `billing_events` | The append-only audit ledger | Not owner-scoped |
| `billing_number_sequences` | The gapless invoice-number counter, per scope | Not owner-scoped |

**Purged** — operational data with no reason to outlive the person it belongs to. Deleted outright.

**Retained** — the financial record. A valid invoice has to carry the buyer's name and address, and invoices
have to be kept for years, so the right to erasure yields to the retention obligation: the row is **unlinked**
from the owner (`owner_type` and `owner_id` go null, `owner_erased_at` is stamped) and kept until
`billing:prune` ages it out.

**Scrubbed** — the row survives, its personal data does not. The delivery row is the dedup that keeps a
redelivery from being processed twice; the payload inside it carries the customer's email, name, billing
address and card last four, so the payload is nulled.

**Cascaded** — a child keyed to its parent rather than to the owner. The eraser reaches it by joining through
the parent, deliberately **not** by trusting the foreign key: SQLite enforces foreign keys only when
`PRAGMA foreign_keys` is on, and it is off by default, so an erasure obligation resting on the cascade would
fail silently on the one engine where nothing would look wrong. The cascade stays as defense in depth.

**Not owner-scoped** — no personal data keyed to an owner. The audit ledger is the exception worth naming:
it records who did what, and it is aged out on its own clock (`billing.retention.audit_days`) rather than by
an erasure request.

## Columns

Timestamps (`created_at`, `updated_at`) are on every table and are not repeated below. A column marked `?` is
nullable.

### `billing_subscriptions`

`owner_type` + `owner_id` · `type` (default `default`, so one owner can hold several subscriptions) ·
`provider` · `provider_id?` · `status` · `tier_key?` · `scheduled_tier_key?` · `scheduled_swap_at?` ·
`trial_ends_at?` · `ends_at?` · `delinquent_since?` · `dunning_level` (default `0`) · `synced_event_at?` ·
`current_period_start?` · `current_period_end?`

Unique on (`owner_type`, `owner_id`, `type`), indexed on (`owner_type`, `owner_id`, `status`).

`delinquent_since` is a timestamp rather than a gateway status: the dunning ladder counts days from it, so a
provider outage cannot reset an owner's position on the ladder. `synced_event_at` is the ordering guard —
an out-of-order webhook cannot move the row backwards.

### `billing_subscription_items`

`billing_subscription_id` (foreign key, cascades on delete) · `plan_key` · `price_ref?` · `quantity?` ·
`metered` (default `false`) · `amount_minor?` · `currency` · `preprocessor?`

Unique on (`billing_subscription_id`, `plan_key`) — the dedup that stops a repeated cycle build from adding
every line twice and doubling the amount.

### `billing_orders`

`owner_type` + `owner_id` · `provider` · `subscription_id?` (foreign key, nulls on delete) · `total_minor` ·
`currency` · `status` (default `open`) · `period_start?` · `period_end?` · `processed_at?` ·
`payment_reference?`

Unique on (`subscription_id`, `period_start`), indexed on (`owner_type`, `owner_id`, `status`). A null
`subscription_id` does not collide, so an owner may have many one-off orders.

### `billing_order_items`

`order_id` (foreign key, cascades on delete) · `description` · `unit_price_minor` · `quantity` (default `1`) ·
`total_minor` · `currency` · `tax_bps?` · `type` · `metadata?` (JSON)

Tax is basis points, not a percentage: the money layer is integer-only, and a percentage float here would be
the one place a rounding error could enter.

### `billing_invoices`

`owner_type?` + `owner_id?` · `owner_erased_at?` · `provider?` · `provider_id?` · `number?` · `total_minor` ·
`subtotal_minor?` · `tax_minor?` · `currency` · `status` (default `draft`) · `issued_at?` ·
`credited_invoice_id?` · `credited_invoice_number?` · `buyer?` (JSON) · `lines?` (JSON) · `reverse_charge`
(default `false`) · `buyer_reference?` · `vat_note?` · `oss` (default `false`) · `destination_country?` ·
`oss_rate?`

Unique on `number` and on (`provider`, `provider_id`).

The owner columns are nullable because a retained invoice outlives the owner. `total_minor` and
`subtotal_minor` are **signed**: a credit note is a negative document, not a positive one with a flag.
`buyer` and `lines` are snapshots taken when the invoice is finalized — the document does not change when a
customer later edits their address. `buyer_reference` carries the routing id a public buyer requires
(EN 16931 BT-10) and `vat_note` the exemption reason (BT-120).

### `billing_addon_purchases`

`owner_type?` + `owner_id?` · `owner_erased_at?` · `reference` (unique) · `addon_key` · `amount_minor` ·
`currency` · `payment_reference?` (indexed) · `reversed_minor` (default `0`) · `revoked_at?` ·
`revoked_reason?`

`reference` is the idempotency key: a redelivered purchase event lands on the same row instead of granting a
second time.

### `billing_credit_balances`

`owner_type` + `owner_id` · `currency` · `balance_minor` (default `0`)

Unique on (`owner_type`, `owner_id`, `currency`) — one balance per currency, never a mixed-currency total.

### `billing_prepaid_units`

`owner_type` + `owner_id` · `meter_key` · `balance` (default `0`) · `granted_total` (default `0`)

Unique on (`owner_type`, `owner_id`, `meter_key`). Prepaid units never expire; the tier's per-cycle allowance
does, and usage spends the allowance first.

### `billing_usage_counters`

`owner_type` + `owner_id` · `meter_key` · `period` · `used` (default `0`) · `reserved` (default `0`) ·
`prepaid_used` (default `0`) · `warned_at?`

Unique on (`owner_type`, `owner_id`, `meter_key`, `period`).

### `billing_usage_events`

`owner_type` + `owner_id` · `meter_key` · `provider_meter?` · `quantity` · `prepaid_units` (default `0`) ·
`occurred_at` · `period` · `identifier` (unique) · `source_key?` (unique) · `state` (default `pending`) ·
`reported_at?` · `attempts` (default `0`) · `next_attempt_at?` · `last_error?` · `rolled_up_into?` ·
`is_rollup` (default `false`)

Indexed on (`state`, `next_attempt_at`) — the flush's own query — and on
(`owner_type`, `owner_id`, `meter_key`, `period`).

This is an outbox, not a log: a row stays until the provider has accepted it, and `attempts` plus
`next_attempt_at` carry the backoff. `identifier` makes recording idempotent, `source_key` deduplicates
against the caller's own key.

### `billing_usage_reservations`

`token` (ULID, unique) · `owner_type` + `owner_id` · `meter_key` · `period` · `amount` · `included?` ·
`state` (default `pending`) · `expires_at`

Indexed on (`state`, `expires_at`). Every hold expires, so a worker killed between claiming an allowance and
recording the usage cannot hold it forever.

### `billing_cancellation_surveys`

`owner_type` + `owner_id` · `reason` (indexed) · `detail?`

Only written when an owner gives a reason. The survey never blocks or delays the cancellation.

### `billing_coupons` and `billing_coupon_redemptions`

`billing_coupons`: `code` (unique) · `type` · `value` · `currency?` · `duration` · `duration_in_cycles?` ·
`max_redemptions?` · `redeemed_count` (default `0`) · `expires_at?` · `provider_coupon_id?` · `active`
(default `true`)

`billing_coupon_redemptions`: `owner_type` + `owner_id` · `coupon_id` (foreign key, cascades on delete) ·
`subscription_id?` (indexed) · `redeemed_at`, unique on (`coupon_id`, `owner_type`, `owner_id`) so a code
cannot be redeemed twice by the same owner.

### `billing_webhook_events`

`provider` · `event_id` · `type` · `owner_type?` + `owner_id?` · `payload?` (JSON) · `status` (default
`pending`) · `last_error?` · `handled_at?`

Unique on (`provider`, `event_id`) — the idempotency key that makes a redelivery a no-op — and indexed on
(`status`, `created_at`).

### `billing_webhook_effect_runs`

`provider` · `reference` · `effect` · `delivery_id?` · `status` (default `pending`) · `attempts` (default
`0`) · `last_error?` · `handled_at?`

Unique on (`provider`, `reference`, `effect`), indexed on `status` and on `delivery_id`. Idempotency is per
**effect**, not per delivery, so replaying a delivery whose third effect failed re-runs only that one.

### `billing_events`

`type` (indexed) · `source` (default `system`, indexed) · `subject_type?` + `subject_id?` ·
`actor_type?` + `actor_id?` · `payload` (JSON)

Append-only: rows are never updated, and the only sanctioned deletion is the retention purge in
`billing:prune`. `source` separates a system action from one a human took, and `actor` records who.

### `billing_number_sequences`

`scope` (unique) · `next_number` (default `1`)

The invoice-number counter. Numbering must be gapless, so the next number is claimed from this row inside a
transaction rather than derived by counting invoices.

## Your own table

`billing:install` generates one migration against `billing.customer.model`'s table. Every column is guarded
by `hasColumn()`, so it is safe to run after Cashier's own customer migration:

- the tier column (`billing.tier_column`, default `plan`) — the denormalized tier the hot path reads
- the customer column (`billing.customer.column`, default `stripe_id`), indexed
- `pm_type`, `pm_last_four`, `trial_ends_at` — Cashier's columns, added only if absent

Both column names come from the config the package reads at runtime, never from a literal: a consumer who
renamed one and got a migration for the default name would end up with a column nothing writes and a package
reading a column that does not exist.

The rollback drops only the columns that migration created, each guarded, so Cashier's own columns are never
dropped by it.

---

[← Back to the documentation index](../README.md)
