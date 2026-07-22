# Contributing

Thanks for considering a contribution. This package holds itself to a strict quality
bar, and every change is expected to keep all of the gates green.

## Getting started

```bash
git clone git@github.com:pushery/billing-for-laravel.git
cd billing-for-laravel
composer install
just setup   # one-time: wires the release-gate git hook
```

## Quality gates

All of the following must pass. The aggregate static + test gate is:

```bash
composer qa
```

which runs, and each can be run on its own:

| Command | Gate |
|---|---|
| `composer format:test` | Code style — Laravel Pint, zero diffs (`composer format` to fix). |
| `composer rector:test` | Refactoring — Rector with the PHP rule set, dry-run clean (`composer rector` to apply). |
| `composer analyze` | Static analysis — Larastan at `max` level, no errors. |
| `composer test:type-coverage` | 100% type coverage of `src/`. |
| `composer test:coverage` | 100% line coverage of `src/`. |

The suite uses [Pest](https://pestphp.com) and Orchestra Testbench.

The full local gate — including the real-browser end-to-end suite and mutation
testing — is `just all`. It runs on **your machine**, not GitHub Actions (a private
package should not burn Actions minutes on every push). A pre-push hook, wired once
by `just setup`, blocks a push to `main` unless `just all` last passed on exactly
that commit. Emergency bypass: `git push --no-verify`.

## Pull request expectations

- Keep `composer qa` green.
- Add tests for behavior changes.
- Update `README.md` and `CHANGELOG.md` (`## [Unreleased]`) when behavior or
  configuration changes.
- Keep commits focused and the public API stable, or call out the break explicitly.

## Upgrading the Stripe API version

The package pins the Stripe API version it is tested against
(`StripeServiceProvider::STRIPE_API_VERSION`), rather than inheriting whatever the
installed SDK ships. Stripe versions the *shape* of webhook payloads, so a version
change can silently stop a real billing event from firing — the mapper reads fields
defensively and degrades to nothing. Moving the pin is therefore a deliberate ritual,
never a side effect of a dependency bump:

1. Read the [Stripe API changelog](https://docs.stripe.com/changelog) for the new
   dated version. Note any removed or renamed fields on `Subscription`, `Invoice`,
   `Charge`, `PaymentIntent` or `Checkout\Session` — those are what the mapper reads.
2. Bump `STRIPE_API_VERSION` to the new dated version.
3. Run the live-Stripe suite against a real test account:
   `STRIPE_TEST_SECRET=sk_test_... composer test:stripe-live`. The mapper smoke maps
   real objects through the real mapper, so a field the mapper depends on going away
   turns this red instead of quiet.
4. Point each Stripe webhook endpoint at the new version, then run `billing:doctor`
   against the account — it fails if any endpoint still renders an older shape.
5. Ship, with a `CHANGELOG.md` entry noting the version move.

`stripe/stripe-php` is a direct dependency with a floor-tight, range-open constraint:
safety comes from the version header the package sends, not from freezing the SDK.
Renovate isolates its updates into their own PR so this ritual runs before a bump
merges — never auto-merge it.
