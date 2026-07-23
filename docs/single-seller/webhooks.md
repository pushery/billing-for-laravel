# Webhooks

Point your provider at the configured `billing.webhook_path` (default `billing/webhook`). Deliveries are
verified by signature, de-duplicated on the event id, and dispatched to registered effects. The shipped
effects sync the owner's plan, credit a one-time add-on exactly once, send a dunning notice, and persist each
finalized invoice. A one-time add-on's credit is mirrored onto the Stripe customer balance, so it is applied
automatically against the customer's next invoice and shown to them in the account hub — not a number that
only lives in the database. In production the package refuses to boot without a webhook signing secret.

Each effect runs as its own queued job, so one that throws no longer takes the ones after it down with it,
each retries on its own, and each leaves a record of what it did or still owes. A raw-payload ledger keeps
every delivery so a failed effect can be re-driven with `billing:webhooks:replay --failed`, rather than
depending on the provider to redeliver (which it stops doing after its own retry window).

For the neutral domain events these effects listen on, and how to listen or fake them in your own app, see
the [Event reference](../reference/events.md) and the [Testing guide](../guides/testing.md).

---

[← Back to the documentation index](../README.md)
