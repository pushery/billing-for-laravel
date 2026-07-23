# Dunning and suspension

## Suspension on a delinquency rung

Withdraw a surface from a delinquent owner with `423 Locked` once they reach a configured dunning rung:

```php
Route::middleware('billing.suspend:api')->group(/* … */);
```

The delinquency clock is a stored timestamp, so lockout keeps working during a provider outage — and nobody
is locked out unannounced: `billing:dunning:advance` (scheduled daily) walks the configured ladder, sending
each rung's warning once its day arrives and charging that rung's late fee if you set one.

## The hard case — a failed card

For the harder case — payment has actually failed (a `past_due`/`incomplete` subscription) — put
`billing.dunning` on the surfaces that need a working card. A browser request is **redirected to the
payment-recovery screen** (so the customer lands on "update your card", not a dead error); an API/JSON
request gets `402 Payment Required` (configurable via `billing.dunning_status`). The recovery screen itself
is never blocked, so there is no redirect loop. Like the suspension gate, the decision reads only the local
subscription row — no provider call on the hot path.

```php
Route::middleware(['auth', 'billing.dunning'])->group(/* … the surfaces that need a paid, current card … */);
```

A pause is never treated as delinquency: it does not start the delinquency clock or walk the owner up the
ladder.

---

[← Back to the documentation index](../README.md)
