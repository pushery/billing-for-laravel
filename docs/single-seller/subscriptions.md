# Subscriptions

A visitor becomes a subscriber from the `/plan` screen. The client submits the tier _key_ only — the price
is resolved server-side (anti-price-injection) — and the package opens a hosted Checkout Session in
subscription mode. The trial, provider tax and VAT-id collection, promotion codes and the billing address
all ride on that session, and the card is captured (with SCA / 3-D Secure) on the provider's own page. On
return, `/checkout/return` reconciles the subscription onto the local row immediately, so a paying customer
is never shown "Free" while the webhook is still in flight. An owner who already subscribes swaps in-app
instead of opening a second subscription.

The return URLs default to the hub's own routes; set `billing.checkout.success_url` / `cancel_url` only to
override them. **Set `billing.customer.model` to your billable model** — without it, no subscription webhook
can find its owner.

## Swaps, cancel, resume, trials

- In-app upgrade/downgrade **swap** with a proration **preview**; an upgrade takes effect immediately, a
  downgrade is scheduled to the current period end so the customer is never charged twice or refunded for
  time they already paid.
- Cancel-into-grace (paid through the period end), resume, and immediate cancel from the danger zone.
- **Trials** — `none` / `subscription` / `generic`, global or per-tier, card required or `if_required`; a
  trial-ending reminder goes out before the first charge.

## Localized hub and emails

The hub and its emails ship translated in English, German, Spanish, French, Italian, Dutch and Portuguese,
with an informal register throughout. Publish the views or the translations to customize them:

```bash
php artisan vendor:publish --tag=billing-views
php artisan vendor:publish --tag=billing-lang
```

Every publishable asset also sits under a shared `billing` umbrella tag, so `php artisan vendor:publish
--tag=billing` publishes the config, migrations, views and translations in one go.

---

[← Back to the documentation index](../README.md)
