# Contract reference

Everything crosses a small set of contracts, so your app talks to _billing_ — not to a provider. The two-layer
core is `PaymentRails` (moves money, stores mandates) and `BillingEngine` (the recurring cycle); money crosses
the boundary as a `Money` value object, never a raw provider response.

## Extension seams

Bind your own implementation of any of these to change behavior without touching the core:

- `TierResolver` — maps a billable to its tier (`ColumnTierResolver` by default; `SubscriptionTierResolver`
  reads the active price back).
- `BillingEntityResolver` — resolves the actor that owns billing (the user, or its team).
- `TaxCalculator` — the local tax-computation path (EU-OSS table / none).
- `VatIdValidator` — validates a buyer's VAT id (a VIES-backed implementation ships; the null default proves
  nothing offline).
- The notifier contracts — dunning, trial-ending, receipt, payment-action-required, and the rest.

A custom implementation must honor the contract's guarantee: a resolver returns a stable answer for the same
owner, a calculator never invents a rate it cannot justify, and a money-moving seam is idempotency-keyed.

## Drivers

| Capability | Stripe (shipping) | Mollie / Adyen (planned) |
| --- | --- | --- |
| Subscriptions, proration, trials | native | package-local engine |
| Invoices / PDF | native | generated locally |
| Hosted portal | native | not available |
| Webhooks | signed | bare-id / HMAC |

The neutral contracts and the `BillingEngine::tick()` seam ship today; the Mollie and Adyen drivers themselves
are not built. Under Stripe, `tick()` is a deliberate no-op — Stripe drives its own recurring cycle.

## The Cashier coupling, and where it stops

The Stripe driver builds on the raw `stripe/stripe-php` SDK, not on Cashier's models or its `Billable` trait —
the package reimplements checkout, the portal, invoices, subscriptions and payment methods itself so the same
neutral contracts can back a future non-Cashier driver. Cashier is used for exactly two things, both confined
to the driver layer: it supplies the `cashier.*` config namespace the driver reads Stripe credentials from
(`cashier.secret`, `cashier.webhook.secret`), and `Cashier::ignoreRoutes()` is what the master switch calls to
drop Cashier's own routes when billing is off. The one place a raw Cashier/provider object is intentionally
passed straight through is the hosted invoice-PDF download response, which is streamed as the provider returns
it. An architecture test (`tests/Unit/ArchTest.php`) fails the build if any `Stripe\` or `Laravel\Cashier\`
import, or a hardcoded `->stripe_id` read, leaks outside `src/Drivers/Stripe/`, so this boundary cannot erode.

---

[← Back to the documentation index](../README.md)
