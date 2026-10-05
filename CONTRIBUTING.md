# Contributing

Thanks for considering a contribution — issues and pull requests are both welcome.

## Reporting an issue

Use the GitHub issue templates (bug report / feature request). Include the package
version and a minimal reproduction, and never paste secrets or credentials.

## Pull requests

- Keep the public API stable, or call out the break explicitly.
- Describe the behavior change and how to reproduce it. **This repository ships no test
  suite** — see below — so a pull request here cannot carry one; the tests are written
  alongside your change in the development repository.
- Update `README.md` and the `CHANGELOG.md` `## [Unreleased]` section.
- Keep each commit focused.

## Local requirements

**PHP 8.4.1 or newer** to work on the package, even though the package itself installs
on 8.4.0. The test toolchain (Pest 5 → `symfony/process`) is what raises the floor, so
on exactly 8.4.0 `composer install` fails with a message about `symfony/process` rather
than about Pest. Upgrade the patch version; nothing else is wrong.

## Quality bar

This package holds itself to a strict quality bar — Laravel Pint, Larastan at `max`,
Rector, and Pest with 100% line and type coverage, plus mutation testing, a
real-browser end-to-end suite, and cross-engine tests against real PostgreSQL and
MySQL 8.4 (the engines it runs on in production).

**That bar runs in the development repository, not here.** This repository is a
read-only mirror of the released tree: it carries the package itself — the source, its
configuration, migrations, routes, views and translations — and deliberately not the test
suite, the task runner or the CI configuration. So none of the commands above exist in a
clone of this repository, and there is nothing here for you to run them against.

What that means in practice: send the change with a clear description and a minimal
reproduction. It is rerun against the full bar on the way in, and the tests for it are
written there. Saying plainly what you could not verify is more useful than a claim that
sounds checked.
