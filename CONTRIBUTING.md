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
| `composer test:coverage` | 100% line coverage of everything that ships — `src/`, `config/`, `database/` and `routes/`. |

The suite uses [Pest](https://pestphp.com) and Orchestra Testbench.

## Testing against real databases

`composer test` runs on in-memory SQLite, which is fast and is not the whole story. Two
further suites re-run the database-touching cases against **real servers** — the engines
this package is meant to run on:

| Suite | Engine | Environment |
|---|---|---|
| `tests/Postgres/` | PostgreSQL | `PG_TEST_HOST`, `PG_TEST_PORT` (default 5432), `PG_TEST_USER`, `PG_TEST_PASSWORD` |
| `tests/MySql/` | MySQL 8.4+ | `MYSQL_TEST_HOST`, `MYSQL_TEST_PORT` (default **3308**), `MYSQL_TEST_USER`, `MYSQL_TEST_PASSWORD` |

The MySQL port default is deliberately **not** 3306. On a development machine 3306 is
usually whatever MySQL was installed first, which is often below this package's 8.4
floor; 3308 is where Herd puts its 8.4. CI sets `MYSQL_TEST_PORT=3306` because its
service genuinely is 8.4. Point it at a legacy server and the run fails on the engine
check rather than skipping — which is correct, and confusing if you expected 3306 to
be the default.

Run just those with `composer test:database`; `composer test` includes them too.

**A suite SKIPS when its server is unreachable, so a bare checkout stays green — which
means a green run is not by itself evidence that either engine was tested.** Set
`REQUIRE_DB_TESTS=1` to turn an unreachable server into a hard failure. CI does; do it
locally too, or "all tests pass" can quietly mean "SQLite passed".

You do not create the target database. The harness probes the server through a
maintenance connection and creates it on demand, including a per-worker copy under
`pest --parallel`, so the connecting role needs `CREATEDB` (PostgreSQL) or `CREATE`
(MySQL) and nothing more.

A reachable server of the wrong **engine** fails rather than skips. MariaDB is not a
substitute for MySQL here and is deliberately rejected: version 11.4 clears an 8.4 floor
numerically, so a version-only check would report a green MySQL lane for a server that is
not MySQL. The same applies to PostgreSQL wire-compatible engines.

**A database-touching test belongs in every suite it is relevant to** — keep it in
`tests/Feature` for the fast loop *and* mirror it into `tests/Postgres` and `tests/MySql`,
so engine-specific behavior (JSON vs JSONB, `RETURNING`, strict mode, collation, identifier
casing, index types, whether a foreign key gets an index) is caught here rather than in a
user's application. A guard in the suite enforces the mirroring, and a deliberate exception
carries its reason.

The full local gate — including the real-browser end-to-end suite and mutation
testing — is `just all`. It runs on **your machine**, not GitHub Actions (a private
package should not burn Actions minutes on every push). A pre-push hook, wired once
by `just setup`, blocks a push to `main` unless `just all` last passed on exactly
that commit. Emergency bypass: `git push --no-verify`.

## Pull request expectations

- Keep `composer qa` green.
- Add tests for behavior changes.
- Update `README.md` when behavior or configuration changes.
- Add a changelog entry as a **fragment**: a new file `changelog.d/<your-branch>.md`
  holding a `### Added`/`### Fixed`/… heading and your entry. Do not edit
  `CHANGELOG.md` directly — every entry landing in the same section of the same file
  makes two open pull requests conflict over text that is purely additive, and each
  resolution costs another full CI run. The fragments are folded into a release block
  at release time. Format and rationale: `changelog.d/README.md`.
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
