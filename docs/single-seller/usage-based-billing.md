# Usage-based billing

A tier can charge for usage on top of its base fee. Declare what it meters in `config/billing.php`:

```php
'pro' => [
    'label' => 'Pro',
    'provider_price' => env('BILLING_PRICE_PRO'),        // 19 €/month, the base fee
    'price_display' => ['amount' => 1900, 'currency' => 'EUR'],

    'metered' => [
        'emails' => [
            'label' => 'Emails sent',
            'unit' => 'email',
            'provider_price' => env('BILLING_PRICE_EMAILS'), // a metered price
            'provider_meter' => 'emails_sent',               // the meter it reports into
            'package_size' => 1000,                          // billed per 1 000
            'unit_price' => ['amount' => 50, 'currency' => 'EUR'],
            'included' => 10000,                             // first 10 000 free
        ],
    ],
],
```

Then record usage from your own send path — the one call does everything:

```php
app(Pushery\Billing\Support\UsageRecorder::class)->record($team, 'emails', 42_000, sourceKey: "campaign:{$campaign->id}");
```

It moves the owner's counter (what the usage screen shows) and writes the outbox row the provider is billed
from, in a single local write — so what a customer sees and what they are charged for come from the same
place. `billing:usage:flush` (scheduled every minute) reports it.

The parts that would cost real money if they were wrong:

- **A retried job bills once.** Pass a `sourceKey` and the same usage is recorded once, however often your job runs.
- **A retried _report_ bills once.** The provider identifier is minted when the usage is recorded and replayed unchanged, so the provider dedups it.
- **A provider outage delays billing; it does not lose it.** Reports back off and retry. Usage that truly cannot be reported is marked failed and logged as an error — never dropped quietly.
- **Usage is reported raw.** The allowance and the packaging live in the provider's price and are applied once, to the cycle's total. Configure the same `included` / `package_size` on the provider's price (a graduated tier priced at 0 up to the allowance) — the values in config drive the gauge.
- **Usage follows the subscription's cycle**, not the calendar month, so an owner who renews on the 17th is billed for the right window.

A tier that bills for usage on a driver that cannot report usage refuses to boot, rather than counting every
unit and invoicing none of them.

## Quota enforcement

A `billing.quota:<meter>` middleware (and a `UsageGate`) applies the meter's policy: a hard-stop / refuse
meter is blocked past its allowance, a degrade meter still serves but is flagged, a fair-use meter never
blocks. For a limit that must not be oversold, meter the work through `UsageRecorder::meter()` — it HOLDS the
allowance under a row lock before the work runs, records only what the work actually consumed, and hands the
rest back. The middleware is the cheap pre-check in front of it: on its own it is a point-in-time read, and
two simultaneous requests can both pass a boundary check.

## Prepaid units (an add-on that grants usage, not credit)

An add-on can hand the owner **units of a meter** instead of money:

```php
'addons' => [
    'extra_emails' => [
        'label' => 'Extra emails',
        'provider_price' => 'price_...',
        'price_display' => ['amount' => 3000, 'currency' => 'EUR'],
        'grants' => ['meter' => 'emails', 'units' => 1000],
    ],
],
```

- **The free allowance is spent first, the bought units only after it** — never the other way round, or the customer's own units burn while free ones sit unused.
- **`included` expires with the cycle; prepaid never does.** Paid is paid, so unused units roll forever.
- **Prepaid-covered usage is netted out before the provider is told.** The provider's price knows nothing about prepaid, so reporting the raw total would bill the customer a second time for units they had already bought. (The tier's `included` allowance is *not* netted locally — that one lives in the provider's price.)
- **A refund claws back only what is left.** Units already consumed delivered their value and stay spent; the unused remainder comes back, proportionally to the money returned.
- The reservation lock defends the prepaid balance too, so a bought unit is never handed to two concurrent requests.

Read a balance with `app(Pushery\Billing\Support\PrepaidLedger::class)->balance($owner, 'emails')`.

---

[← Back to the documentation index](../README.md)
