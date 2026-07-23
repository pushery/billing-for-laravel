# The account hub

When `billing.enabled` is on, the hub mounts under `config('account.prefix')` (default `account/billing`)
behind your `web` + `auth` middleware:

| Route | Screen |
| --- | --- |
| `/` | Overview + tier summary |
| `/subscription` | State, next-invoice preview, cancel / resume, portal link |
| `/plan` | Subscribe (hosted checkout) or in-app swap with a proration preview |
| `/payment-methods` | List, set-default, remove, add |
| `/invoices` | History + ownership-checked PDF download |
| `/usage` | Metered dimensions |
| `/usage/history` | Past periods + add-on top-up timeline |
| `/recovery` | Dunning recovery surface |
| `/danger` | Immediate cancel |
| `/portal` | Redirect to the provider's hosted billing portal |
| `/checkout/return` | Reconciles the subscription after a hosted checkout |

See [Subscriptions](subscriptions.md) for the subscribe/swap flow behind `/plan`.

Add the shell banner to your layout — it renders nothing for a healthy account:

```blade
<x-billing::banner />
```

Every screen renders inside a **publishable app shell** (`layouts/account.blade.php`): a grouped sidebar
navigation with the active item marked, a skip link to the main content, a typed document title, and a
POST-logout form shown only when your app registers a `logout` route. It needs no UI-kit dependency; publish
`billing-views` to replace it with your own design system's shell.

If an **external merchant of record** owns billing (an app-store subscription, an external portal), set
`billing.link_out` (env `BILLING_LINK_OUT`) to that portal's URL: the plan screen then links out to it and
suppresses the in-app checkout it is not the merchant of record for. On a native runtime, set
`billing.runtime=native` (env `BILLING_RUNTIME`) to hide flows an app store forbids in-app.

## Hosting your own screens in the hub

The hub navigation is config-driven, and the hub _hosts_ screens it does not own. To slot one of your own app
or auth screens — sessions, connections, set-password, an onboarding step — into the hub, register its route
in `billing.navigation` (an optional `group` places it in a labeled section,
`billing::account.nav.group.<group>`):

```php
// config/billing.php
'navigation' => [
    'sessions' => ['label' => 'account.sessions', 'route' => 'app.sessions', 'group' => 'account', 'order' => 60],
],
```

The entry appears in the navigation **only once that route actually exists**, so the same config can name a
section your app builds later — an unregistered route (or one that needs parameters the hub cannot supply) is
silently dropped, never rendered as a broken link. The package ships no ancillary-screen classes of its own;
it only slots your route into the shell.

---

[← Back to the documentation index](../README.md)
