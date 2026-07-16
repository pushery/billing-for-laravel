<?php

declare(strict_types=1);

namespace Pushery\Billing\Facades;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use Override;
use Pushery\Billing\Contracts\Checkout;
use Pushery\Billing\Contracts\OneTimeCharge;
use Pushery\Billing\Contracts\SubscriptionActions;
use Pushery\Billing\Testing\BillingFake;

/**
 * A testing facade for the money-mutating billing seams. Call {@see Billing::fake()} in a test to bind a
 * recording {@see BillingFake} to the Checkout, SubscriptionActions and OneTimeCharge contracts, then
 * assert what the app WOULD have done — `Billing::assertSubscribeStarted($owner, 'pro')`,
 * `Billing::assertSwapped(...)`, `Billing::assertNothingCharged()` — exactly like `Bus::fake()`.
 *
 * @method static void assertSubscribeStarted(Model $owner, string $tierKey)
 * @method static void assertNothingSubscribed()
 * @method static void assertSwapped(Model $owner, string $tierKey)
 * @method static void assertCanceled(Model $owner)
 * @method static void assertResumed(Model $owner)
 * @method static void assertCanceledNow(Model $owner)
 * @method static void assertPurchased(Model $owner, string $addonKey)
 * @method static void assertNothingCharged()
 *
 * @see BillingFake
 */
final class Billing extends Facade
{
    /** Bind a recording fake to the three money seams (and this facade) and return it. */
    public static function fake(): BillingFake
    {
        $fake = new BillingFake;

        app()->instance(Checkout::class, $fake);
        app()->instance(SubscriptionActions::class, $fake);
        app()->instance(OneTimeCharge::class, $fake);

        self::swap($fake);

        return $fake;
    }

    #[Override]
    protected static function getFacadeAccessor(): string
    {
        return BillingFake::class;
    }
}
