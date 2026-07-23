# Testing

```bash
composer test        # unit + feature + cross-engine (Postgres + MySQL)
composer qa          # style, static analysis, type + line coverage
```

The Unit and Feature suites run on fast in-memory SQLite; two further suites re-run against real PostgreSQL
and MySQL 8.4 so engine-specific behavior is proven on the databases Laravel Cloud runs.

## Testing your own billing logic

You do not need to reach Stripe to test the code you build on this package. Two seams make it straightforward.

**Assert on the neutral domain events.** Every webhook the package processes is translated into a
provider-neutral event (`SubscriptionStateChanged`, `AddonPurchased`, `PaymentFailed`, `InvoiceFinalized`,
`MandateRevoked`, …) and dispatched through Laravel's own dispatcher, so `Event::fake()` and
`Event::assertDispatched()` work exactly as they do for your app's events:

```php
use Illuminate\Support\Facades\Event;
use Pushery\Billing\Events\SubscriptionStateChanged;

Event::fake([SubscriptionStateChanged::class]);

// … deliver a webhook, or run a reconcile …

Event::assertDispatched(SubscriptionStateChanged::class,
    fn (SubscriptionStateChanged $e) => $e->tierKey === 'pro');
```

**Fake the outbound actions.** The provider-mutating seams are contracts, so bind a fake to keep a test off
the network and assert on what your code asked for:

```php
use Pushery\Billing\Contracts\SubscriptionActions;

$actions = Mockery::spy(SubscriptionActions::class);
$this->app->instance(SubscriptionActions::class, $actions);

// … exercise the code that swaps a plan …

$actions->shouldHaveReceived('swap')->with($user, 'pro', true);
```

The same applies to `Checkout` (open a subscription) and `OneTimeCharge` (buy an add-on). No card data, no
live call, no webhook secret needed.

**Or use the recording fake.** For the common case, `Billing::fake()` binds a recording fake to all three
seams at once and gives you ready-made assertions — the same shape as `Bus::fake()`:

```php
use Pushery\Billing\Facades\Billing;

Billing::fake();

// … exercise the code that subscribes the user and buys an add-on …

Billing::assertSubscribeStarted($user, 'pro');
Billing::assertSwapped($user, 'premium');
Billing::assertNothingCharged();
```

---

[← Back to the documentation index](../README.md)
