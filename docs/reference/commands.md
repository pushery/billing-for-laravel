# Command reference

## Scheduled commands

The service provider registers these for you:

| Command | Cadence | What it does |
| --- | --- | --- |
| `billing:usage:flush` | every minute | Reports recorded usage to the provider that bills it |
| `billing:run` | hourly | Advances the recurring cycle (a no-op under Stripe, which drives its own) |
| `billing:dunning:advance` | daily | Walks the dunning ladder: escalating warnings + late fees |
| `billing:cards:warn` | daily | Warns owners whose card is about to expire |
| `billing:usage:reconcile` | daily | Reads the provider's usage totals back and alarms on drift or recorded-but-unbilled usage |
| `billing:prune` | daily | Ages out stored webhook payloads and expired financial records |

## On-demand commands

```bash
php artisan billing:sync            # reconcile subscriptions from the provider onto the local rows
php artisan billing:install         # publish the config + generate the owner-columns migration
php artisan billing:webhooks:replay --failed   # re-drive webhook effects that failed
php artisan billing:erase {owner}   # erase an owner's billing data (see Data protection)
php artisan billing:export {owner}  # everything the package holds about one owner, as JSON
php artisan billing:doctor          # check your Stripe webhook endpoints render the pinned API version
php artisan billing:meters:check    # verify every configured usage meter exists and is active at the provider
php artisan billing:usage:reconcile --redrive  # (also scheduled daily) retry the rollups a flush gave up on
php artisan billing:tier:grant {owner} {tier}  # comp an owner onto a tier out of band, recorded on the audit trail
php artisan billing:datev:export    # a period of invoices as a DATEV EXTF booking batch (defaults to last month)
```

## Notes on exit behavior

`billing:meters:check` catches a metered tier whose `provider_meter` was never created, or was archived, at
the provider — usage reported into a meter that does not exist fails silently, and the miss surfaces (if ever)
as an under-charged invoice a month later. It exits non-zero when a meter is missing, so it fits a deploy
check. `billing:usage:reconcile` answers "is there any recorded-but-unbilled usage right now?" — after fixing
the cause (often a meter `billing:meters:check` found), `--redrive` returns the failed rollups to pending so
the next flush retries them.

`billing:sync` is the bulk version of the post-checkout reconcile — use it to backfill after a webhook outage.
It applies each subscription through the same plan-sync effect the webhook uses, so it can never overwrite a
newer webhook state; it only moves a stale local row forward. Scope it with `--owner`, preview with
`--dry-run`.

`billing:tier:grant` is the terminal form of a support comp (the same `BillingAdmin::comp` an admin panel
calls): it writes the tier column directly and records the grant on the audit trail. It refuses a tier key no
`billing.tiers` entry declares, and warns when the tier is not in `billing.untouchable_tiers` — because the
next provider webhook is otherwise free to overwrite the grant.

---

[← Back to the documentation index](../README.md)
