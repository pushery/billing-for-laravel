<?php

declare(strict_types=1);

namespace Pushery\Billing\Testing;

use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Assert as PHPUnit;
use Pushery\Billing\Contracts\Checkout;
use Pushery\Billing\Contracts\OneTimeCharge;
use Pushery\Billing\Contracts\SubscriptionActions;
use Pushery\Billing\Facades\Billing;
use Pushery\Billing\ValueObjects\CancellationSurvey;
use Pushery\Billing\ValueObjects\ClientIntent;

/**
 * A recording fake for the three money-mutating seams — {@see Checkout}, {@see SubscriptionActions} and
 * {@see OneTimeCharge}. Bind it (via {@see Billing::fake()}) and the app's billing
 * flows record their intent instead of talking to a provider, so a consumer's test can assert what WOULD
 * have happened — the same convenience as `Bus::fake()` / `Notification::fake()`, but for billing.
 *
 * The subscribe/purchase seams return a harmless fake ClientIntent (a redirect that goes nowhere), so a
 * screen under test still gets a URL to redirect to without a real hosted checkout.
 */
final class BillingFake implements Checkout, OneTimeCharge, SubscriptionActions
{
    /** @var list<array{owner: Model, tier: string, coupon: ?string}> */
    private array $subscribes = [];

    /** @var list<array{owner: Model, tier: string, prorate: bool}> */
    private array $swaps = [];

    /** @var list<array{owner: Model, action: string, survey?: ?CancellationSurvey}> */
    private array $lifecycle = [];

    /** @var list<array{owner: Model, addon: string}> */
    private array $purchases = [];

    public function subscribe(Model $billable, string $tierKey, ?string $couponCode = null): ClientIntent
    {
        $this->subscribes[] = ['owner' => $billable, 'tier' => $tierKey, 'coupon' => $couponCode];

        return $this->intent();
    }

    public function purchase(Model $billable, string $addonKey): ClientIntent
    {
        $this->purchases[] = ['owner' => $billable, 'addon' => $addonKey];

        return $this->intent();
    }

    public function cancel(Model $billable, ?CancellationSurvey $survey = null): void
    {
        $this->lifecycle[] = ['owner' => $billable, 'action' => 'cancel', 'survey' => $survey];
    }

    public function resume(Model $billable): void
    {
        $this->lifecycle[] = ['owner' => $billable, 'action' => 'resume'];
    }

    public function cancelNow(Model $billable): void
    {
        $this->lifecycle[] = ['owner' => $billable, 'action' => 'cancelNow'];
    }

    public function swap(Model $billable, string $tierKey, bool $prorate = true): void
    {
        $this->swaps[] = ['owner' => $billable, 'tier' => $tierKey, 'prorate' => $prorate];
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
