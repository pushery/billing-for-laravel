<?php

declare(strict_types=1);

namespace Pushery\Billing\Facades;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use Override;
use Pushery\Billing\Contracts\CanReceiveMoney;
use Pushery\Billing\Contracts\Checkout;
use Pushery\Billing\Contracts\MerchantAccountDirectory;
use Pushery\Billing\Contracts\MerchantOnboarding;
use Pushery\Billing\Contracts\OneTimeCharge;
use Pushery\Billing\Contracts\StartsSubscriptions;
use Pushery\Billing\Contracts\SubscriptionActions;
use Pushery\Billing\Testing\BillingFake;
use Pushery\Billing\Testing\FakeMarketplaceRails;

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

        Container::getInstance()->instance(Checkout::class, $fake);
        // Both subscribe seams, because a screen may go through either. Faking only the hosted-checkout one
        // left a consumer's Subscribe button resolving the real driver-neutral starter -- which under a
        // local driver writes an intent row and calls the mandate rails, in a test that asked for a fake.
        Container::getInstance()->instance(StartsSubscriptions::class, $fake);
        Container::getInstance()->instance(SubscriptionActions::class, $fake);
        Container::getInstance()->instance(OneTimeCharge::class, $fake);
        // The receiving side too: a marketplace consumer's test would otherwise hit the real onboarding
        // seam and the real gate, which is a provider call and a database read respectively.
        Container::getInstance()->instance(MerchantOnboarding::class, $fake);
        Container::getInstance()->instance(CanReceiveMoney::class, $fake);

        self::swap($fake);

        return $fake;
    }

    /**
     * Bind a recording stand-in for the RECEIVING side and return it.
     *
     * Separate from {@see fake()} by design — that one stands in for the seams that move a buyer's money,
     * this one for the receiving side — and binding them together would quietly replace a consumer's own
     * rails in tests that never asked for it.
     *
     * ## They are not disjoint, and the overlap has an order
     *
     * `@shared-bindings:` MerchantOnboarding
     *
     * Both fakes implement `MerchantOnboarding` in full, and both bind it. **Whichever factory is called
     * LAST owns that binding** for the rest of the test — so a test that calls both and then resolves
     * `MerchantOnboarding` from the container gets the second one, and assertions against the first see
     * nothing at all.
     *
     * That is not a defect to fix by dropping one of them: onboarding is a genuine part of both surfaces,
     * and a consumer may reasonably want either recording. It is a defect to leave UNSAID, which it was —
     * this block claimed the two were separate while they shared a contract.
     *
     * If a test needs both fakes and asserts on onboarding, call the one it asserts on **second**, or hold
     * the returned object and go through it rather than through the container. `EveryFakeOverlapIsDocumented`
     * fails if the shared set ever grows beyond what this annotation names.
     */
    public static function fakeMarketplace(): FakeMarketplaceRails
    {
        $rails = new FakeMarketplaceRails;

        Container::getInstance()->instance(MerchantOnboarding::class, $rails);
        Container::getInstance()->instance(MerchantAccountDirectory::class, $rails);

        return $rails;
    }

    #[Override]
    protected static function getFacadeAccessor(): string
    {
        return BillingFake::class;
    }
}
