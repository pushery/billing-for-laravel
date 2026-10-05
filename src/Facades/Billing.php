<?php

declare(strict_types=1);

namespace Pushery\Billing\Facades;

use Carbon\CarbonInterface;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use LogicException;
use Override;
use Pushery\Billing\Contracts\CanReceiveMoney;
use Pushery\Billing\Contracts\Checkout;
use Pushery\Billing\Contracts\MerchantAccountDirectory;
use Pushery\Billing\Contracts\MerchantOnboarding;
use Pushery\Billing\Contracts\OneTimeCharge;
use Pushery\Billing\Contracts\StartsSubscriptions;
use Pushery\Billing\Contracts\SubscriptionActions;
use Pushery\Billing\Enums\TaxArchetype;
use Pushery\Billing\Testing\BillingFake;
use Pushery\Billing\Testing\FakeMarketplaceRails;
use Pushery\Billing\ValueObjects\MerchantScope;
use Pushery\Billing\ValueObjects\Money;

/**
 * A testing facade for the money-mutating billing seams. Call {@see Billing::fake()} in a test to bind a
 * recording {@see BillingFake} to the Checkout, SubscriptionActions and OneTimeCharge contracts, then
 * assert what the app WOULD have done — `Billing::assertSubscribeStarted($owner, 'pro')`,
 * `Billing::assertSwapped(...)`, `Billing::assertNothingCharged()` — exactly like `Bus::fake()`.
 *
 * Without `fake()` the facade refuses rather than answering: nothing would have recorded what the code under
 * test did, so a negative assertion would pass over a real cancel or a real charge.
 *
 * @method static void assertSubscribeStarted(Model $owner, string $tierKey)
 * @method static void assertSubscribeStartedWithCoupon(Model $owner, string $tierKey, ?string $couponCode)
 * @method static void assertSubscribeStartedWithDeclaration(Model $owner, string $tierKey, ?string $declarationReference)
 * @method static void assertSubscribeStartedWithType(Model $owner, string $tierKey, ?string $type)
 * @method static void assertSubscribeStartedWithCallerReference(Model $owner, string $tierKey, ?string $callerReference)
 * @method static void assertNothingSubscribed()
 * @method static void assertSwapped(Model $owner, string $tierKey, ?string $type = null)
 * @method static void assertCanceled(Model $owner, ?MerchantScope $merchant = null, ?string $type = null)
 * @method static void assertCanceledAt(Model $owner, ?CarbonInterface $endsAt = null, ?MerchantScope $merchant = null, ?string $type = null)
 * @method static void assertResumed(Model $owner, ?MerchantScope $merchant = null, ?string $type = null)
 * @method static void assertCanceledNow(Model $owner, ?MerchantScope $merchant = null, ?string $type = null)
 * @method static void assertNotCanceled(Model $owner, ?MerchantScope $merchant = null, ?string $type = null)
 * @method static void assertNotCanceledAt(Model $owner, ?MerchantScope $merchant = null, ?string $type = null)
 * @method static void assertNotResumed(Model $owner, ?MerchantScope $merchant = null, ?string $type = null)
 * @method static void assertNotCanceledNow(Model $owner, ?MerchantScope $merchant = null, ?string $type = null)
 * @method static void assertPurchased(Model $owner, string $addonKey)
 * @method static void assertPurchasedWithDeclaration(Model $owner, string $addonKey, ?string $declarationReference)
 * @method static void assertNothingCharged()
 * @method static void assertTipped(Model $owner, Money $chosen, TaxArchetype $soldAlongside)
 * @method static void assertNothingTipped()
 * @method static void assertOnboardingStarted(Model $merchant, ?string $refreshUrl = null, ?string $returnUrl = null)
 * @method static void assertNothingOnboarded()
 * @method static void assertReceiveGateDenied(Model $merchant)
 *
 * @see BillingFake
 */
final class Billing extends Facade
{
    /**
     * Bind a recording fake to the seams that charge a buyer and to the receiving side, and to this facade, and
     * return it.
     *
     * Six contracts: the hosted checkout and the subscription starter, the subscription actions, the one-time
     * charge, merchant onboarding and the receive gate. The fake gate refuses every merchant until
     * `allowMerchantsToReceive()` is called, so a test that relies on its own `CanReceiveMoney` binds it again
     * after this call, or allows on the fake.
     */
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
     * Bind a recording stand-in for merchant onboarding and the merchant account directory, and return it.
     *
     * {@see fake()} covers part of the receiving side, onboarding and the receive gate. This one is the fuller
     * stand-in for onboarding, with the account directory a merchant's screens read, and it leaves the buyer's
     * seams and the receive gate as they are.
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
     * and a consumer may reasonably want either recording.
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

    /**
     * The fake `fake()` set, and nothing else. Resolved from the container instead, the facade built a fresh
     * fake nothing was bound to: the code under test reached the real seams, and `assertNothingCharged()`
     * passed over a charge it never saw.
     *
     * @param  string  $name
     */
    #[Override]
    protected static function resolveFacadeInstance(mixed $name): mixed
    {
        if (! isset(self::$resolvedInstance[$name])) {
            throw new LogicException(
                'Call Billing::fake() before asserting on Billing. Without it nothing records what the code under test '
                .'did, and the real billing seams answered it.'
            );
        }

        return self::$resolvedInstance[$name];
    }
}
