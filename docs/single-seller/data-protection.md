# Data protection

The package stores personal data on your behalf — the customer's name and address on an invoice, and the raw
provider webhook payloads, which carry their email, name, billing address and the last four digits of their
card. So it ships the two things a GDPR request actually needs.

```bash
php artisan billing:export 42            # everything we hold about owner 42, as JSON (Art. 15 / Art. 20)
php artisan billing:erase 42             # erase it (Art. 17)
php artisan billing:erase 42 --dry-run   # …or see first what would go and what would stay
```

**`billing:erase` deliberately does not delete the invoices.** A valid invoice has to carry the buyer's name
and address (§14 UStG), and invoices have to be kept for years (§147 AO, §14b UStG) — the right to erasure
yields to a legal retention obligation (Art. 17(3)(b)). Those rows are unlinked from the owner and kept, and
`billing:prune` removes them once the retention window closes (`billing.retention.erased_financial_days`,
default eight years — check it against your own jurisdiction). Everything else goes: subscriptions, usage,
credit balances, the owner's own stored provider API keys, and the personal data inside the webhook payloads.

A credit balance is money you still owed the customer, so it is written to the audit ledger before it is
purged rather than vanishing quietly.

`billing.erasure.forget_customer` additionally DELETES the customer at the provider. That is irreversible and
it cancels their live subscriptions there, so it is **off by default** — turn it on deliberately. Stripe keeps
its own invoice and charge records regardless.

**The command is the complete path.** An observer on your `User` model would look more convenient, but a mass
delete (`User::query()->where(...)->delete()`) fires no model events at all — an app relying on one would
under-erase and never know. Call `billing:erase` (or the `BillingEraser`) from wherever you handle the
request.

**Deleting an account stops live billing first.** A deleted owner whose subscription keeps charging is a
money leak (and billing someone you erased is a compliance breach). `billing:erase` / `BillingEraser` fire a
`BillableAccountDeleting` event before they erase, and the package cancels the owner's subscription
immediately (not into a grace period) in response — tolerant of a provider blip, which is logged and lets the
delete continue rather than leaving a user who asked to leave undeletable. If your app has its **own** delete
UI that does not go through `BillingEraser`, dispatch the event yourself, right after re-confirming identity
and before `$user->delete()`:

```php
use Pushery\Billing\Events\BillableAccountDeleting;

event(new BillableAccountDeleting($user));   // cancels billing now
$user->delete();
```

`billing:prune` also ages out the stored webhook payloads on its own clock
(`billing.retention.webhook_payload_days`, default 90). They exist for exactly one reason — so a failed effect
can be re-driven from what the provider already sent — and the provider itself stops redelivering after about
three days. A payload whose effects are still owed is never pruned, however old it is.

For the full retention and deletion matrix, see [Retention and erasure](../compliance/retention-and-erasure.md).

---

[← Back to the documentation index](../README.md)
