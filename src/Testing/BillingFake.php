<?php

declare(strict_types=1);

namespace Pushery\Billing\Testing;

use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Assert as PHPUnit;
use Pushery\Billing\Contracts\CanReceiveMoney;
use Pushery\Billing\Contracts\Checkout;
use Pushery\Billing\Contracts\MerchantOnboarding;
use Pushery\Billing\Contracts\OneTimeCharge;
use Pushery\Billing\Contracts\SubscriptionActions;
use Pushery\Billing\Facades\Billing;
use Pushery\Billing\ValueObjects\CancellationSurvey;
use Pushery\Billing\ValueObjects\ClientIntent;
use Pushery\Billing\ValueObjects\MerchantAccountReference;
use Pushery\Billing\ValueObjects\MerchantScope;

/**
 * A recording fake for the three money-mutating seams — {@see Checkout}, {@see SubscriptionActions} and
 * {@see OneTimeCharge}. Bind it (via {@see Billing::fake()}) and the app's billing
 * flows record their intent instead of talking to a provider, so a consumer's test can assert what WOULD
 * have happened — the same convenience as `Bus::fake()` / `Notification::fake()`, but for billing.
 *
 * The subscribe/purchase seams return a harmless fake ClientIntent (a redirect that goes nowhere), so a
 * screen under test still gets a URL to redirect to without a real hosted checkout.
 */
final class BillingFake implements CanReceiveMoney, Checkout, MerchantOnboarding, OneTimeCharge, SubscriptionActions
{
    /** @var list<array{owner: Model, tier: string, coupon: ?string}> */
    private array $subscribes = [];

    /** @var list<array{owner: Model, tier: string, prorate: bool, merchant: ?MerchantScope}> */
    private array $swaps = [];

    /** @var list<array{owner: Model, action: string, survey?: ?CancellationSurvey, merchant: ?MerchantScope}> */
    private array $lifecycle = [];

    /** @var list<array{owner: Model, addon: string}> */
    private array $purchases = [];

    /** @var list<array{merchant: Model, refresh: string, return: string}> */
    private array $onboardings = [];

    /** @var list<array{merchant: Model, allowed: bool}> */
    private array $receiveChecks = [];

    /**
     * What the receive gate answers. It defaults to DENY, matching the fail-closed contract it stands in
     * for: a fake that permitted by default would let a consumer's test pass over a path production would
     * have refused, which is the one thing a billing fake must never do.
     */
    private bool $merchantsMayReceive = false;

    public function subscribe(Model $billable, string $tierKey, ?string $couponCode = null): ClientIntent
    {
        $this->subscribes[] = ['owner' => $billable, 'tier' => $tierKey, 'coupon' => $couponCode];

        return $this->intent();
    }

    public function purchase(Model $billable, string $addonKey, ?string $declarationReference = null): ClientIntent
    {
        // Recorded, not dropped. A consumer asserting that their checkout collected the declarations has
        // nothing else to assert against -- the key is the only observable the package produces before the
        // buyer leaves, and a fake that swallowed it would make the round trip untestable from outside.
        $this->purchases[] = ['owner' => $billable, 'addon' => $addonKey, 'declaration' => $declarationReference];

        return $this->intent();
    }

    public function cancel(Model $billable, ?CancellationSurvey $survey = null, ?MerchantScope $merchant = null): void
    {
        $this->lifecycle[] = ['owner' => $billable, 'action' => 'cancel', 'survey' => $survey, 'merchant' => $merchant];
    }

    public function resume(Model $billable, ?MerchantScope $merchant = null): void
    {
        $this->lifecycle[] = ['owner' => $billable, 'action' => 'resume', 'merchant' => $merchant];
    }

    public function cancelNow(Model $billable, ?MerchantScope $merchant = null): void
    {
        $this->lifecycle[] = ['owner' => $billable, 'action' => 'cancelNow', 'merchant' => $merchant];
    }

    public function swap(Model $billable, string $tierKey, bool $prorate = true, ?MerchantScope $merchant = null): void
    {
        $this->swaps[] = ['owner' => $billable, 'tier' => $tierKey, 'prorate' => $prorate, 'merchant' => $merchant];
    }

    // ── Assertions ──────────────────────────────────────────────────────────────────────────────────────

    public function assertSubscribeStarted(Model $owner, string $tierKey): void
    {
        $found = false;

        foreach ($this->subscribes as $call) {
            if ($this->sameOwner($call['owner'], $owner) && $call['tier'] === $tierKey) {
                $found = true;
            }
        }

        PHPUnit::assertTrue($found, "Expected a checkout for tier [{$tierKey}] to have started, but it did not.");
    }

    public function assertNothingSubscribed(): void
    {
        PHPUnit::assertSame([], $this->subscribes, 'Expected no checkout to have started, but at least one did.');
    }

    public function assertSwapped(Model $owner, string $tierKey): void
    {
        $found = false;

        foreach ($this->swaps as $call) {
            if ($this->sameOwner($call['owner'], $owner) && $call['tier'] === $tierKey) {
                $found = true;
            }
        }

        PHPUnit::assertTrue($found, "Expected a swap to tier [{$tierKey}], but it did not happen.");
    }

    public function assertCanceled(Model $owner): void
    {
        $this->assertLifecycle($owner, 'cancel');
    }

    public function assertResumed(Model $owner): void
    {
        $this->assertLifecycle($owner, 'resume');
    }

    public function assertCanceledNow(Model $owner): void
    {
        $this->assertLifecycle($owner, 'cancelNow');
    }

    public function assertPurchased(Model $owner, string $addonKey): void
    {
        $found = false;

        foreach ($this->purchases as $call) {
            if ($this->sameOwner($call['owner'], $owner) && $call['addon'] === $addonKey) {
                $found = true;
            }
        }

        PHPUnit::assertTrue($found, "Expected add-on [{$addonKey}] to have been purchased, but it was not.");
    }

    public function assertNothingCharged(): void
    {
        PHPUnit::assertSame([], $this->purchases, 'Expected no add-on to have been charged, but at least one was.');
    }

    /** Answer the receive gate with a yes for the rest of the test — the merchant is fully onboarded. */
    public function allowMerchantsToReceive(): self
    {
        $this->merchantsMayReceive = true;

        return $this;
    }

    public function check(Model $merchant): bool
    {
        $this->receiveChecks[] = ['merchant' => $merchant, 'allowed' => $this->merchantsMayReceive];

        return $this->merchantsMayReceive;
    }

    public function createAccount(Model $merchant): MerchantAccountReference
    {
        // Deliberately NOT receivable. A fake account that claimed all three capabilities would let a test
        // route money the moment onboarding started, which is exactly the sequence the real gate forbids:
        // the provider confirms capabilities later, and often not at all.
        return new MerchantAccountReference(provider: 'fake', accountId: 'acct_fake');
    }

    public function onboardingLink(Model $merchant, string $refreshUrl, string $returnUrl): ClientIntent
    {
        $this->onboardings[] = ['merchant' => $merchant, 'refresh' => $refreshUrl, 'return' => $returnUrl];

        return new ClientIntent(driver: 'fake', payload: [
            'url' => 'https://billing.test/fake-onboarding',
            'account' => 'acct_fake',
        ]);
    }

    public function assertOnboardingStarted(Model $merchant): void
    {
        $found = false;

        foreach ($this->onboardings as $call) {
            if ($this->sameOwner($call['merchant'], $merchant)) {
                $found = true;
            }
        }

        PHPUnit::assertTrue($found, 'Expected merchant onboarding to have started, but it did not.');
    }

    public function assertNothingOnboarded(): void
    {
        PHPUnit::assertSame([], $this->onboardings, 'Expected no merchant onboarding to have started, but at least one did.');
    }

    /**
     * The gate was asked about this merchant AND said no.
     *
     * Both halves are asserted on purpose. "The gate denied" is worth nothing without "the gate was
     * consulted": a routed payment that never reached the gate at all would otherwise satisfy an assertion
     * that it was refused.
     */
    public function assertReceiveGateDenied(Model $merchant): void
    {
        $denied = false;

        foreach ($this->receiveChecks as $call) {
            if ($this->sameOwner($call['merchant'], $merchant) && $call['allowed'] === false) {
                $denied = true;
            }
        }

        PHPUnit::assertTrue($denied, 'Expected the receive gate to have denied the merchant, but it did not refuse one.');
    }

    private function assertLifecycle(Model $owner, string $action): void
    {
        $found = false;

        foreach ($this->lifecycle as $call) {
            if ($this->sameOwner($call['owner'], $owner) && $call['action'] === $action) {
                $found = true;
            }
        }

        PHPUnit::assertTrue($found, "Expected the subscription action [{$action}] for the owner, but it did not happen.");
    }

    private function sameOwner(Model $a, Model $b): bool
    {
        return $a->getMorphClass() === $b->getMorphClass() && $a->getKey() === $b->getKey();
    }

    private function intent(): ClientIntent
    {
        return new ClientIntent(driver: 'fake', payload: ['checkout_url' => 'https://billing.test/fake-checkout']);
    }
}
