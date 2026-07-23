# Choosing your setup

Six decisions shape how the package sits in your app. Make them before `billing:install`, because two of
them — the billable model and who owns billing — decide which table the generated migration adds columns to.

Each has a safe default you can revisit later, except the billable model: it has no useful default, and it
is the one setting a real payment depends on.

## 1. Which model is the customer?

`billing.customer.model` is the Eloquent model that owns the provider's customer record, and
`billing.customer.column` is where that reference is stored (`stripe_id`, Cashier's default).

```php
'customer' => ['model' => App\Models\User::class, 'column' => 'stripe_id'],
```

There is **no default**. A clone still boots without it, but no subscription webhook can resolve its owner,
so a paying customer's plan is never synced and they keep seeing the free tier.

If your billable model is not `App\Models\User`, set `BILLING_CUSTOMER_MODEL` **before** you run
`billing:install` — otherwise the generated migration adds the billing columns to `users`.

→ [Installation](single-seller/installation.md)

## 2. Does a user pay, or does a team?

`billing.owner` is `user` (each user is their own billing owner) or `team` (the user's team owns the
subscription and pays for seats). With `team`, `billing.team_relation` names the relation on the acting user
that returns the paying team, and the `billing.seats.*` keys tell the package how to count active members
and which of **your** membership events re-sync the billed quantity.

A user-owner app ignores the seats block entirely. Nothing fires until you list your own events under
`billing.seats.membership_events` — the package does not own your membership table.

→ [Subscriptions](single-seller/subscriptions.md)

## 3. Are you the seller, or is someone else?

By default your app sells the subscription: checkout, invoices and payment methods all live in your app.

If an **external merchant of record** owns billing instead — an app store's subscription management, or an
external portal — set `billing.link_out` to that portal's URL. The account hub then links out to it rather
than offering in-app checkout it is not the merchant of record for. The value is scheme-restricted (an
absolute `http`/`https` URL with a host), so a malformed value simply leaves link-out off.

Related, and easy to miss: `billing.runtime`. Set it to `native` in a mobile webview and the navigation items
flagged `web_only` — account deletion, the link out to an external portal — are hidden, because an app store
forbids completing those flows in-app.

→ [The account hub](single-seller/account-hub.md)

## 4. How is tax computed?

`billing.tax` picks one of three. It is a driver-capability decision, not a per-checkout option:

| Value | What happens |
| --- | --- |
| `none` (default) | Tax is never added. Fine while you sell into one country and price gross. |
| `provider` | The provider computes and remits tax on the invoice (Stripe Tax). |
| `eu_oss` | The bundled static EU-OSS VAT table computes it locally. |

The `TaxCalculator` contract is the seam for anything else — a local rate table, an external tax service —
without touching the checkout path.

Separately, `billing.company.*` carries your own details as the seller party. They are required for EN 16931
e-invoices (XRechnung / ZUGFeRD) and unused if you never emit one.

→ [Taxes](single-seller/taxes.md) · [Invoices and e-invoicing](single-seller/invoices-and-e-invoicing.md)

## 5. Whose screens?

The package ships a publishable account hub — overview, subscription, change plan, payment methods, invoices,
usage, payment recovery, danger zone — mounted at `account.prefix` behind `account.middleware`.
`billing.navigation` decides which sections exist and in what order; removing an entry removes the section.

Point `account.layout` at your own Blade layout to frame the hub in your chrome, and leave the scoped
Content-Security-Policy on (`account.csp`) so the payment element loads on the billing screens only.

You can also skip the hub and call the same contracts from your own screens. Nothing in the package requires
its own UI.

→ [The account hub](single-seller/account-hub.md)

## 6. Do tiers unlock features, or only cost money?

`config/billing.php` is what a customer **pays**. `config/license.php` is what a tier **unlocks** — boolean
feature grants and numeric limits. They are two orthogonal files on purpose: billing code never reads
`license.*` config (an architecture test enforces it), and neither ever blocks a public or marketing surface.

If your tiers differ only in price, leave `license.php` empty. If they gate features, fill it in and ask the
`License` contract rather than comparing tier keys by hand.

→ [Configuration](single-seller/configuration.md) · [Tiers and pricing](single-seller/tiers-and-pricing.md)

---

With these decided, go to [Installation](single-seller/installation.md) and read the guide in order.

---

[← Back to the documentation index](README.md)
