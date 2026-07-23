# i18n and translations

The hub, its emails and every pricing bullet render from a publishable translation namespace. It ships in
English, German, Spanish, French, Italian, Dutch and Portuguese, and it is yours to override, extend or
narrow.

## Publishing the namespace

```bash
php artisan vendor:publish --tag=billing-lang
```

The files land under `lang/vendor/billing/{locale}/`. A published file **overrides the shipped one key by
key**: a key you leave out falls back to the package's translation, so you can publish a single locale and
change only the handful of strings you care about.

## Overriding a key

Publish the locale, then edit the key in the published file. Pricing bullets are the common case — a tier's
`features` block names translation **keys**, not raw text (see
[Tiers and pricing](tiers-and-pricing.md)), so you change the string in one place and both the in-app grid
and `/pricing` move with it:

```php
// lang/vendor/billing/en/pricing.php
return [
    'pro' => [
        'projects' => 'Unlimited projects',        // was the shipped default; now yours
        'priority_support' => 'Priority support',
    ],
];
```

## Adding or removing a locale

- **Add one** — create `lang/vendor/billing/{locale}/` and translate the keys. Your app's own locale
  negotiation decides when it is served; the package reads whatever locale is active.
- **Remove one** — if your app never serves a locale, you do not have to publish it. The package only renders
  the active locale, and an unshipped locale simply falls back through Laravel's normal chain.

Keep a locale you DO serve **complete**: a missing key renders as the raw key string to a customer. The
package's own locales are kept at full parity by a test (below); hold your overrides to the same bar.

## The register: informal, hand-curated

The shipped namespace addresses the customer **informally** throughout — `du` in German, `tu`/`tú` in the
Romance locales — a deliberate house register, not a per-string choice. The translations are **hand-curated
per locale, not machine-translated**: the wording is chosen to read naturally in each language rather than
transliterated from the English. If you override a string or add a locale, keep that register — a formal
`Sie`/`Vous` in one place reads as a bug against the rest of the hub.

## The parity and quality gate

Three tests hold the namespace to its contract, and they are the bar to hold your own overrides to:

- **Key parity** (`LocaleParityTest`) — every locale carries exactly the same set of keys, so no locale is
  missing a string another has.
- **Informality** (`TranslationQualityTest`) — no formal-address marker slips into any locale.
- **Key existence** (`LangKeyExistenceTest`) — every key the code and views reference actually exists.

Run them with `composer test`; they are part of the same suite the coverage gate runs.

---

[← Back to the documentation index](../README.md)
