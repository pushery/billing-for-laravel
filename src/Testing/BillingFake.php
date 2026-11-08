<?php

declare(strict_types=1);

namespace Pushery\Billing\Testing;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Assert as PHPUnit;
use Pushery\Billing\Contracts\CanReceiveMoney;
use Pushery\Billing\Contracts\Checkout;
use Pushery\Billing\Contracts\MerchantOnboarding;
use Pushery\Billing\Contracts\OneTimeCharge;
use Pushery\Billing\Contracts\StartsSubscriptions;
use Pushery\Billing\Contracts\SubscriptionActions;
use Pushery\Billing\Enums\BillingInterval;
use Pushery\Billing\Enums\SubscriptionState;
use Pushery\Billing\Enums\TaxArchetype;
use Pushery\Billing\Facades\Billing;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\ValueObjects\CancellationSurvey;
use Pushery\Billing\ValueObjects\ClientIntent;
use Pushery\Billing\ValueObjects\MerchantAccountReference;
use Pushery\Billing\ValueObjects\MerchantScope;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\SubscriptionStart;

/**
 * A recording fake for the seams {@see Billing::fake()} binds: the ones that move a buyer's money ({@see Checkout},
 * {@see StartsSubscriptions}, {@see SubscriptionActions}, {@see OneTimeCharge}) and the receiving side
 * ({@see MerchantOnboarding}, and {@see CanReceiveMoney}, which refuses every merchant until
 * `allowMerchantsToReceive()`). Bind it (via {@see Billing::fake()}) and the app's billing
 * flows record their intent instead of talking to a provider, so a consumer's test can assert what would
 * have happened — the same convenience as `Bus::fake()` / `Notification::fake()`, but for billing.
 *
 * The subscribe/purchase seams return a harmless fake ClientIntent (a redirect that goes nowhere), so a
 * screen under test still gets a URL to redirect to without a real hosted checkout.
 */
final class BillingFake implements CanReceiveMoney, Checkout, MerchantOnboarding, OneTimeCharge, StartsSubscriptions, SubscriptionActions
{
    /** @var list<array{owner: Model, tier: string, coupon: ?string, declaration: ?string, country: ?string, collectTaxId: ?bool, type: ?string, callerReference: ?string, interval: ?BillingInterval}> */
    private array $subscribes = [];

    /** @var list<array{owner: Model, tier: string, prorate: bool, merchant: ?MerchantScope, type: ?string, interval: ?BillingInterval}> */
    private array $swaps = [];

    /** @var list<array{owner: Model, action: string, survey?: ?CancellationSurvey, endsAt?: CarbonImmutable, merchant: ?MerchantScope, type: ?string}> */
    private array $lifecycle = [];

    /** @var list<array{owner: Model, addon: string, declaration: ?string, country: ?string, collectTaxId: ?bool, callerReference: ?string}> */
    private array $purchases = [];

    /** @var list<array{owner: Model, amount: Money, soldAlongside: TaxArchetype, declaration: ?string, country: ?string}> */
    private array $tips = [];

    /** @var list<array{merchant: Model, refresh: string, return: string}> */
    private array $onboardings = [];

    /** @var list<array{merchant: Model, allowed: bool}> */
    private array $receiveChecks = [];

    /**
     * What the receive gate answers. It defaults to deny, matching the fail-closed contract it stands in
     * for: a fake that permitted by default would let a consumer's test pass over a path production would
     * have refused, which is the one thing a billing fake must never do.
     */
    private bool $merchantsMayReceive = false;

    /**
     * What `honorsCoupon()` answers. Fail-closed for the same reason the receive gate above is: a fake that
     * said yes by default would let a consumer's test show "your code was applied" over an install where no
     * coupon is configured at all, and the screen would be green about something production refuses.
     */
    private bool $couponsAreHonored = false;

    public function subscribe(Model $billable, string $tierKey, ?string $couponCode = null, ?string $declarationReference = null, ?string $buyerCountry = null, ?bool $collectTaxId = null, ?string $type = null, ?string $callerReference = null, ?BillingInterval $interval = null): ClientIntent
    {
        // The type is recorded too, because it is the one argument a consumer cannot verify any other way:
        // it decides which local row the sale will end up in, and a fake that dropped it would let a screen
        // pass its tests while sending every contract to the default row.
        //
        // The caller's own key for the same reason, one step further out: it decides whether the consumer's
        // row can be found again at all, and a fake that dropped it would let a screen pass while sending
        // a business purchase off with no key — which is the case that has no fallback.
        $this->subscribes[] = ['owner' => $billable, 'tier' => $tierKey, 'coupon' => $couponCode, 'declaration' => $declarationReference, 'country' => $buyerCountry, 'collectTaxId' => $collectTaxId, 'type' => $type, 'callerReference' => $callerReference, 'interval' => $interval];

        return $this->intent();
    }

    /**
     * The same record as `subscribe()`, because it is the same act seen through the other contract.
     *
     * Recording into one list is deliberate: a consumer asserting "the customer was sent to subscribe to
     * pro" should not have to know whether the screen they are testing went through the hosted-checkout
     * seam or the driver-neutral one. Which of the two it was is an implementation detail of the driver
     * they configured, and the assertion surface should not move when they change it.
     */
    public function start(Model $billable, string $tierKey, ?string $couponCode = null, ?string $declarationReference = null, ?BillingInterval $interval = null): SubscriptionStart
    {
        $this->subscribes[] = ['owner' => $billable, 'tier' => $tierKey, 'coupon' => $couponCode, 'declaration' => $declarationReference, 'country' => null, 'collectTaxId' => null, 'type' => null, 'callerReference' => null, 'interval' => $interval];

        return new SubscriptionStart(SubscriptionState::Activating, 'https://checkout.test/session');
    }

    public function honorsCoupon(string $code): bool
    {
        return $this->couponsAreHonored && trim($code) !== '';
    }

    /** Answer the coupon question with a yes for the rest of the test -- the code names a live coupon. */
    public function honorCoupons(): self
    {
        $this->couponsAreHonored = true;

        return $this;
    }

    public function purchase(Model $billable, string $addonKey, ?string $declarationReference = null, ?string $buyerCountry = null, ?bool $collectTaxId = null, ?string $callerReference = null): ClientIntent
    {
        // Recorded, not dropped. A consumer asserting that their checkout collected the declarations has
        // nothing else to assert against -- the key is the only observable the package produces before the
        // buyer leaves, and a fake that swallowed it would make the round trip untestable from outside.
        $this->purchases[] = ['owner' => $billable, 'addon' => $addonKey, 'declaration' => $declarationReference, 'country' => $buyerCountry, 'collectTaxId' => $collectTaxId, 'callerReference' => $callerReference];

        return $this->intent();
    }

    public function tip(Model $billable, Money $chosen, TaxArchetype $soldAlongside, ?string $declarationReference = null, ?string $buyerCountry = null): ClientIntent
    {
        // Recorded on its own list rather than beside the purchases, and the separation is what a consumer
        // asserts against: a tip has no add-on key, so a shared list would have to carry a null where every
        // other row carries a string, and `assertPurchased('pro-pack')` would have to learn to skip rows
        // that are not purchases at all.
        $this->tips[] = [
            'owner' => $billable,
            'amount' => $chosen,
            'soldAlongside' => $soldAlongside,
            'declaration' => $declarationReference,
            'country' => $buyerCountry,
        ];

        return $this->intent();
    }

    public function cancel(Model $billable, ?CancellationSurvey $survey = null, ?MerchantScope $merchant = null, ?string $type = null): void
    {
        $this->lifecycle[] = ['owner' => $billable, 'action' => 'cancel', 'survey' => $survey, 'merchant' => $merchant, 'type' => $type];
    }

    public function cancelAt(Model $billable, CarbonInterface $endsAt, ?MerchantScope $merchant = null, ?string $type = null): void
    {
        $this->lifecycle[] = ['owner' => $billable, 'action' => 'cancelAt', 'endsAt' => CarbonImmutable::instance($endsAt), 'merchant' => $merchant, 'type' => $type];
    }

    public function resume(Model $billable, ?MerchantScope $merchant = null, ?string $type = null): void
    {
        $this->lifecycle[] = ['owner' => $billable, 'action' => 'resume', 'merchant' => $merchant, 'type' => $type];
    }

    public function cancelNow(Model $billable, ?MerchantScope $merchant = null, ?string $type = null): void
    {
        $this->lifecycle[] = ['owner' => $billable, 'action' => 'cancelNow', 'merchant' => $merchant, 'type' => $type];
    }

    public function swap(Model $billable, string $tierKey, bool $prorate = true, ?MerchantScope $merchant = null, ?string $type = null, ?BillingInterval $interval = null): void
    {
        $this->swaps[] = ['owner' => $billable, 'tier' => $tierKey, 'prorate' => $prorate, 'merchant' => $merchant, 'type' => $type, 'interval' => $interval];
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

    /**
     * The same checkout, with the coupon the caller actually passed.
     *
     * A separate method rather than an optional third parameter on assertSubscribeStarted(), and that is the
     * whole design: an optional `?string $coupon = null` cannot tell "assert no coupon was passed" from "do
     * not check the coupon", because both spell it `null`. The first is exactly what a consumer validating a
     * code before checkout needs to prove -- that an invalid code did not reach the seam.
     *
     * It matters because the package deliberately ignores a bad code downstream rather than failing the
     * checkout. That is right here and wrong on a consumer's own order form, where showing a discount the
     * buyer never receives is a legal problem rather than a cosmetic one. The value was already recorded;
     * only the way to read it was missing.
     */
    public function assertSubscribeStartedWithCoupon(Model $owner, string $tierKey, ?string $couponCode): void
    {
        $found = false;
        $seen = [];

        foreach ($this->subscribes as $call) {
            if (! $this->sameOwner($call['owner'], $owner) || $call['tier'] !== $tierKey) {
                continue;
            }

            if ($call['coupon'] === $couponCode) {
                $found = true;

                continue;
            }

            $seen[] = $call['coupon'] ?? 'none';
        }

        // The recorded values go in the message. A bare "it did not happen" on a value assertion sends the
        // reader back to add a dump, and the fake is holding the answer already.
        PHPUnit::assertTrue($found, sprintf(
            'Expected a checkout for tier [%s] with coupon [%s], but %s.',
            $tierKey,
            $couponCode ?? 'none',
            $seen === []
                ? 'no checkout for that owner and tier was started at all'
                : 'the coupons seen were ['.implode(', ', $seen).']',
        ));
    }

    /**
     * The subscription start, with the declaration reference the caller actually passed.
     *
     * The subscription twin of assertPurchasedWithDeclaration(), with the same null semantics: `null` asserts that
     * no reference was passed.
     */
    public function assertSubscribeStartedWithDeclaration(Model $owner, string $tierKey, ?string $declarationReference): void
    {
        $found = false;
        $seen = [];

        foreach ($this->subscribes as $call) {
            if (! $this->sameOwner($call['owner'], $owner) || $call['tier'] !== $tierKey) {
                continue;
            }

            if ($call['declaration'] === $declarationReference) {
                $found = true;

                continue;
            }

            $seen[] = $call['declaration'] ?? 'none';
        }

        PHPUnit::assertTrue($found, sprintf(
            'Expected a checkout for tier [%s] with declaration [%s], but %s.',
            $tierKey,
            $declarationReference ?? 'none',
            $seen === []
                ? 'no checkout for that owner and tier was started at all'
                : 'the declarations seen were ['.implode(', ', $seen).']',
        ));
    }

    /**
     * The subscription start, with which contract it opens.
     *
     * Recorded since the type existed and not askable until now, which made the recording a claim rather than
     * a capability: a consumer whose screen dropped the type had no way to notice, and the argument decides
     * which local row the sale lands in. Same null semantics as the others -- `null` asserts the default
     * contract, the state every caller that names nothing is in.
     */
    public function assertSubscribeStartedWithType(Model $owner, string $tierKey, ?string $type): void
    {
        $found = false;
        $seen = [];

        foreach ($this->subscribes as $call) {
            if (! $this->sameOwner($call['owner'], $owner) || $call['tier'] !== $tierKey) {
                continue;
            }

            if (($call['type'] ?? null) === $type) {
                $found = true;

                continue;
            }

            $seen[] = $call['type'] ?? 'none';
        }

        PHPUnit::assertTrue($found, sprintf(
            'Expected a checkout for tier [%s] with contract type [%s], but %s.',
            $tierKey,
            $type ?? 'none (the default contract)',
            $seen === []
                ? 'no checkout for that owner and tier was started at all'
                : 'the types seen were ['.implode(', ', $seen).']',
        ));
    }

    /**
     * The subscription start, with the caller's own correlation key.
     *
     * This is the one a business checkout has to be asserted on, because it is the only key such a purchase
     * carries: there is no declaration reference on a sale to a buyer who has no right of withdrawal. A screen
     * that forgets to pass it sends the sale off with nothing to match the consumer's own row against, and
     * nothing else in a test would notice.
     */
    public function assertSubscribeStartedWithCallerReference(Model $owner, string $tierKey, ?string $callerReference): void
    {
        $found = false;
        $seen = [];

        foreach ($this->subscribes as $call) {
            if (! $this->sameOwner($call['owner'], $owner) || $call['tier'] !== $tierKey) {
                continue;
            }

            if (($call['callerReference'] ?? null) === $callerReference) {
                $found = true;

                continue;
            }

            $seen[] = $call['callerReference'] ?? 'none';
        }

        PHPUnit::assertTrue($found, sprintf(
            'Expected a checkout for tier [%s] with caller reference [%s], but %s.',
            $tierKey,
            $callerReference ?? 'none',
            $seen === []
                ? 'no checkout for that owner and tier was started at all'
                : 'the caller references seen were ['.implode(', ', $seen).']',
        ));
    }

    /**
     * The subscription start, with the buyer's country the caller passed. `null` asserts that none was passed, the
     * state of a start through `StartsSubscriptions`, which takes no country.
     */
    public function assertSubscribeStartedWithCountry(Model $owner, string $tierKey, ?string $buyerCountry): void
    {
        $found = false;
        $seen = [];

        foreach ($this->subscribes as $call) {
            if (! $this->sameOwner($call['owner'], $owner) || $call['tier'] !== $tierKey) {
                continue;
            }

            if ($call['country'] === $buyerCountry) {
                $found = true;

                continue;
            }

            $seen[] = $call['country'] ?? 'none';
        }

        $this->assertFound(
            $found,
            sprintf('a checkout for tier [%s] with buyer country [%s]', $tierKey, $buyerCountry ?? 'none'),
            'no checkout for that owner and tier was started at all',
            'the buyer countries seen were',
            $seen,
        );
    }

    /**
     * The subscription start, with whether the caller asked for the buyer's tax ID. `null` asserts that the caller
     * left it to the configured default.
     */
    public function assertSubscribeStartedWithTaxIdCollection(Model $owner, string $tierKey, ?bool $collectTaxId): void
    {
        $found = false;
        $seen = [];

        foreach ($this->subscribes as $call) {
            if (! $this->sameOwner($call['owner'], $owner) || $call['tier'] !== $tierKey) {
                continue;
            }

            if ($call['collectTaxId'] === $collectTaxId) {
                $found = true;

                continue;
            }

            $seen[] = $this->taxIdCollection($call['collectTaxId']);
        }

        $this->assertFound(
            $found,
            sprintf('a checkout for tier [%s] with tax ID collection [%s]', $tierKey, $this->taxIdCollection($collectTaxId)),
            'no checkout for that owner and tier was started at all',
            'the tax ID collections seen were',
            $seen,
        );
    }

    /**
     * The subscription start, with the interval the caller chose. `null` asserts that none was chosen, so the tier's
     * own interval applies.
     */
    public function assertSubscribeStartedWithInterval(Model $owner, string $tierKey, ?BillingInterval $interval): void
    {
        $found = false;
        $seen = [];

        foreach ($this->subscribes as $call) {
            if (! $this->sameOwner($call['owner'], $owner) || $call['tier'] !== $tierKey) {
                continue;
            }

            if ($call['interval'] === $interval) {
                $found = true;

                continue;
            }

            $seen[] = $call['interval']->value ?? "the tier's own";
        }

        $this->assertFound(
            $found,
            sprintf('a checkout for tier [%s] with interval [%s]', $tierKey, $interval->value ?? "the tier's own"),
            'no checkout for that owner and tier was started at all',
            'the intervals seen were',
            $seen,
        );
    }

    public function assertNothingSubscribed(): void
    {
        PHPUnit::assertSame([], $this->subscribes, 'Expected no checkout to have started, but at least one did.');
    }

    /**
     * The owner's subscription was swapped to this tier.
     *
     * A contract type narrows it to that contract, the way a merchant does in the lifecycle assertions below:
     * null leaves the type out of the question, and `Subscription::TYPE_DEFAULT` names the default contract,
     * which is what a swap without a type addresses. A merchant narrows it to the subscription held with that
     * merchant, and a swap recorded without one counts as the platform's, as it does for the lifecycle actions.
     */
    public function assertSwapped(Model $owner, string $tierKey, ?string $type = null, ?MerchantScope $merchant = null): void
    {
        $found = false;
        $seen = [];

        foreach ($this->swaps as $call) {
            if (! $this->sameOwner($call['owner'], $owner) || $call['tier'] !== $tierKey) {
                continue;
            }

            $recorded = $call['type'] ?? Subscription::TYPE_DEFAULT;
            $scope = ($call['merchant'] ?? MerchantScope::platform())->uid();

            if (($type === null || $recorded === $type) && (! $merchant instanceof MerchantScope || $scope === $merchant->uid())) {
                $found = true;

                continue;
            }

            $seen[] = $merchant instanceof MerchantScope ? $recorded.' under '.$scope : $recorded;
        }

        PHPUnit::assertTrue($found, ($type === null && ! $merchant instanceof MerchantScope) || $seen === []
            ? "Expected a swap to tier [{$tierKey}], but it did not happen."
            : "Expected a swap to tier [{$tierKey}]"
                .($type === null ? '' : " of the [{$type}] contract")
                .($merchant instanceof MerchantScope ? ' under merchant ['.$merchant->uid().']' : '')
                .', but it happened on ['.implode(', ', $seen).'].');
    }

    /**
     * The swap to this tier, prorated or not. Whether the rest of the period is squared at once is the part of a
     * change of tier that moves money, so a screen that offers a change at the end of the period asserts `false`.
     */
    public function assertSwappedWithProration(Model $owner, string $tierKey, bool $prorate): void
    {
        $found = false;
        $seen = [];

        foreach ($this->swaps as $call) {
            if (! $this->sameOwner($call['owner'], $owner) || $call['tier'] !== $tierKey) {
                continue;
            }

            if ($call['prorate'] === $prorate) {
                $found = true;

                continue;
            }

            $seen[] = $call['prorate'] ? 'prorated' : 'not prorated';
        }

        $this->assertFound(
            $found,
            sprintf('a swap to tier [%s] %s', $tierKey, $prorate ? 'prorated' : 'not prorated'),
            'no swap of that owner to that tier happened at all',
            'the swaps to that tier were',
            $seen,
        );
    }

    /** The swap to this tier, with the interval the caller chose. `null` asserts that none was chosen. */
    public function assertSwappedWithInterval(Model $owner, string $tierKey, ?BillingInterval $interval): void
    {
        $found = false;
        $seen = [];

        foreach ($this->swaps as $call) {
            if (! $this->sameOwner($call['owner'], $owner) || $call['tier'] !== $tierKey) {
                continue;
            }

            if ($call['interval'] === $interval) {
                $found = true;

                continue;
            }

            $seen[] = $call['interval']->value ?? "the tier's own";
        }

        $this->assertFound(
            $found,
            sprintf('a swap to tier [%s] with interval [%s]', $tierKey, $interval->value ?? "the tier's own"),
            'no swap of that owner to that tier happened at all',
            'the intervals seen were',
            $seen,
        );
    }

    /**
     * The owner's subscription was canceled, under the given merchant and of the given contract type when one
     * is named. A type of `Subscription::TYPE_DEFAULT` is the default contract; null leaves the type open.
     */
    public function assertCanceled(Model $owner, ?MerchantScope $merchant = null, ?string $type = null): void
    {
        $this->assertLifecycle($owner, 'cancel', $merchant, $type);
    }

    /**
     * The cancellation, with the survey the customer answered: its reason and its detail. `null` asserts that the
     * cancellation carried none. Held to the merchant and the contract type when they are named, as `assertCanceled()`
     * is.
     */
    public function assertCanceledWithSurvey(Model $owner, ?CancellationSurvey $survey, ?MerchantScope $merchant = null, ?string $type = null): void
    {
        $found = false;
        $seen = [];

        foreach ($this->lifecycle as $call) {
            if (! $this->sameOwner($call['owner'], $owner)
                || $call['action'] !== 'cancel'
                || ($merchant instanceof MerchantScope && ($call['merchant'] ?? MerchantScope::platform())->uid() !== $merchant->uid())
                || ($type !== null && ($call['type'] ?? Subscription::TYPE_DEFAULT) !== $type)) {
                continue;
            }

            if ($this->sameSurvey($call['survey'] ?? null, $survey)) {
                $found = true;

                continue;
            }

            $seen[] = $this->survey($call['survey'] ?? null);
        }

        $this->assertFound(
            $found,
            sprintf('a cancellation with survey [%s]', $this->survey($survey)),
            'no such cancellation happened for the owner at all',
            'the surveys seen were',
            $seen,
        );
    }

    /**
     * The owner's subscription was canceled to a moment inside its period: to exactly `$endsAt` when one is named,
     * under the given merchant and of the given contract type when those are.
     *
     * Compared as a moment, not as text, so a moment held in another time zone still matches. A failure names
     * the moments the owner was canceled to, because that is the answer the reader goes looking for.
     */
    public function assertCanceledAt(Model $owner, ?CarbonInterface $endsAt = null, ?MerchantScope $merchant = null, ?string $type = null): void
    {
        if (! $endsAt instanceof CarbonInterface) {
            $this->assertLifecycle($owner, 'cancelAt', $merchant, $type);

            return;
        }

        $seen = [];

        foreach ($this->lifecycle as $call) {
            $moment = $call['endsAt'] ?? null;

            if (! $moment instanceof CarbonImmutable
                || ! $this->sameOwner($call['owner'], $owner)
                || ($merchant instanceof MerchantScope && ($call['merchant'] ?? MerchantScope::platform())->uid() !== $merchant->uid())
                || ($type !== null && ($call['type'] ?? Subscription::TYPE_DEFAULT) !== $type)) {
                continue;
            }

            $seen[] = $moment->utc()->toIso8601String();
        }

        $wanted = CarbonImmutable::instance($endsAt)->utc()->toIso8601String();

        PHPUnit::assertContains($wanted, $seen, $seen === []
            ? "Expected a cancellation to [{$wanted}], but the owner was not canceled to a moment at all."
            : "Expected a cancellation to [{$wanted}], but the owner was canceled to [".implode(', ', $seen).'].');
    }

    /** The owner's subscription was not canceled to a moment, under the given merchant and of the given type when named. */
    public function assertNotCanceledAt(Model $owner, ?MerchantScope $merchant = null, ?string $type = null): void
    {
        $this->assertNoLifecycle($owner, 'cancelAt', $merchant, $type);
    }

    /** The owner's cancellation was taken back, under the given merchant and of the given type when named. */
    public function assertResumed(Model $owner, ?MerchantScope $merchant = null, ?string $type = null): void
    {
        $this->assertLifecycle($owner, 'resume', $merchant, $type);
    }

    /**
     * The owner's subscription was not canceled: under the given merchant when one is named, under none at all when
     * none is. A failure lists every action the owner did see, because that is what the reader of a failing negative
     * assertion goes looking for.
     */
    public function assertNotCanceled(Model $owner, ?MerchantScope $merchant = null, ?string $type = null): void
    {
        $this->assertNoLifecycle($owner, 'cancel', $merchant, $type);
    }

    /** The owner's subscription did not end immediately, under the given merchant and of the given type when named. */
    public function assertNotCanceledNow(Model $owner, ?MerchantScope $merchant = null, ?string $type = null): void
    {
        $this->assertNoLifecycle($owner, 'cancelNow', $merchant, $type);
    }

    /** The owner's cancellation was not taken back, under the given merchant and of the given type when named. */
    public function assertNotResumed(Model $owner, ?MerchantScope $merchant = null, ?string $type = null): void
    {
        $this->assertNoLifecycle($owner, 'resume', $merchant, $type);
    }

    /** The owner's subscription ended immediately, under the given merchant and of the given type when named. */
    public function assertCanceledNow(Model $owner, ?MerchantScope $merchant = null, ?string $type = null): void
    {
        $this->assertLifecycle($owner, 'cancelNow', $merchant, $type);
    }

    /**
     * A tip checkout was opened for this owner, for this amount, on this supply.
     *
     * All three, because any two of them alone leave the interesting mistake passing: an amount without the
     * archetype says nothing about whether the tip was placed on the right supply, and an archetype without
     * the amount says nothing about whether the buyer's figure survived the trip. A consumer asserting a
     * tip is asserting exactly that those three arrived together.
     */
    public function assertTipped(Model $owner, Money $chosen, TaxArchetype $soldAlongside): void
    {
        $found = false;
        $seen = [];

        foreach ($this->tips as $call) {
            if (! $this->sameOwner($call['owner'], $owner)) {
                continue;
            }

            if ($call['amount']->equals($chosen) && $call['soldAlongside'] === $soldAlongside) {
                $found = true;

                continue;
            }

            $seen[] = $call['amount']->minorUnits.' '.$call['amount']->currency.' on '.$call['soldAlongside']->value;
        }

        PHPUnit::assertTrue($found, sprintf(
            'Expected a tip of %d %s paid on [%s], but %s.',
            $chosen->minorUnits,
            $chosen->currency,
            $soldAlongside->value,
            $seen === [] ? 'no tip was opened for this owner' : 'these were: '.implode(', ', $seen),
        ));
    }

    /**
     * The tip, with the declaration reference the caller passed. `null` asserts that none was passed, which is the
     * case for a tip on a supply the buyer has no right of withdrawal for.
     */
    public function assertTippedWithDeclaration(Model $owner, Money $chosen, TaxArchetype $soldAlongside, ?string $declarationReference): void
    {
        $found = false;
        $seen = [];

        foreach ($this->tips as $call) {
            if (! $this->sameOwner($call['owner'], $owner) || ! $call['amount']->equals($chosen) || $call['soldAlongside'] !== $soldAlongside) {
                continue;
            }

            if ($call['declaration'] === $declarationReference) {
                $found = true;

                continue;
            }

            $seen[] = $call['declaration'] ?? 'none';
        }

        $this->assertFound(
            $found,
            sprintf('a tip of %d %s paid on [%s] with declaration [%s]', $chosen->minorUnits, $chosen->currency, $soldAlongside->value, $declarationReference ?? 'none'),
            'no such tip was opened for this owner',
            'the declarations seen were',
            $seen,
        );
    }

    /** The tip, with the buyer's country the caller passed. `null` asserts that none was passed. */
    public function assertTippedWithCountry(Model $owner, Money $chosen, TaxArchetype $soldAlongside, ?string $buyerCountry): void
    {
        $found = false;
        $seen = [];

        foreach ($this->tips as $call) {
            if (! $this->sameOwner($call['owner'], $owner) || ! $call['amount']->equals($chosen) || $call['soldAlongside'] !== $soldAlongside) {
                continue;
            }

            if ($call['country'] === $buyerCountry) {
                $found = true;

                continue;
            }

            $seen[] = $call['country'] ?? 'none';
        }

        $this->assertFound(
            $found,
            sprintf('a tip of %d %s paid on [%s] with buyer country [%s]', $chosen->minorUnits, $chosen->currency, $soldAlongside->value, $buyerCountry ?? 'none'),
            'no such tip was opened for this owner',
            'the buyer countries seen were',
            $seen,
        );
    }

    /** No tip checkout was opened at all — the assertion a screen that hides tipping actually needs. */
    public function assertNothingTipped(): void
    {
        PHPUnit::assertSame([], $this->tips, 'Expected no tip checkout to have been opened, but one was.');
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

    /**
     * The same purchase, with the declaration reference the caller actually passed.
     *
     * The twin of assertSubscribeStartedWithCoupon(), for the same reason and with the same null semantics:
     * `null` asserts that no reference was passed. purchase() already explains why it records the value --
     * "a fake that swallowed it would make the round trip untestable from outside" -- and this is the half
     * that makes good on it.
     */
    public function assertPurchasedWithDeclaration(Model $owner, string $addonKey, ?string $declarationReference): void
    {
        $found = false;
        $seen = [];

        foreach ($this->purchases as $call) {
            if (! $this->sameOwner($call['owner'], $owner) || $call['addon'] !== $addonKey) {
                continue;
            }

            if ($call['declaration'] === $declarationReference) {
                $found = true;

                continue;
            }

            $seen[] = $call['declaration'] ?? 'none';
        }

        PHPUnit::assertTrue($found, sprintf(
            'Expected add-on [%s] to have been purchased with declaration [%s], but %s.',
            $addonKey,
            $declarationReference ?? 'none',
            $seen === []
                ? 'no purchase of that add-on by that owner was recorded at all'
                : 'the declarations seen were ['.implode(', ', $seen).']',
        ));
    }

    /** The purchase, with the buyer's country the caller passed. `null` asserts that none was passed. */
    public function assertPurchasedWithCountry(Model $owner, string $addonKey, ?string $buyerCountry): void
    {
        $found = false;
        $seen = [];

        foreach ($this->purchases as $call) {
            if (! $this->sameOwner($call['owner'], $owner) || $call['addon'] !== $addonKey) {
                continue;
            }

            if ($call['country'] === $buyerCountry) {
                $found = true;

                continue;
            }

            $seen[] = $call['country'] ?? 'none';
        }

        $this->assertFound(
            $found,
            sprintf('add-on [%s] to have been purchased with buyer country [%s]', $addonKey, $buyerCountry ?? 'none'),
            'no purchase of that add-on by that owner was recorded at all',
            'the buyer countries seen were',
            $seen,
        );
    }

    /**
     * The purchase, with whether the caller asked for the buyer's tax ID. `null` asserts that the caller left it to
     * the configured default.
     */
    public function assertPurchasedWithTaxIdCollection(Model $owner, string $addonKey, ?bool $collectTaxId): void
    {
        $found = false;
        $seen = [];

        foreach ($this->purchases as $call) {
            if (! $this->sameOwner($call['owner'], $owner) || $call['addon'] !== $addonKey) {
                continue;
            }

            if ($call['collectTaxId'] === $collectTaxId) {
                $found = true;

                continue;
            }

            $seen[] = $this->taxIdCollection($call['collectTaxId']);
        }

        $this->assertFound(
            $found,
            sprintf('add-on [%s] to have been purchased with tax ID collection [%s]', $addonKey, $this->taxIdCollection($collectTaxId)),
            'no purchase of that add-on by that owner was recorded at all',
            'the tax ID collections seen were',
            $seen,
        );
    }

    /**
     * The purchase, with the caller's own correlation key. A business purchase carries no declaration reference, so
     * this is the key a screen selling to businesses has to be asserted on.
     */
    public function assertPurchasedWithCallerReference(Model $owner, string $addonKey, ?string $callerReference): void
    {
        $found = false;
        $seen = [];

        foreach ($this->purchases as $call) {
            if (! $this->sameOwner($call['owner'], $owner) || $call['addon'] !== $addonKey) {
                continue;
            }

            if ($call['callerReference'] === $callerReference) {
                $found = true;

                continue;
            }

            $seen[] = $call['callerReference'] ?? 'none';
        }

        $this->assertFound(
            $found,
            sprintf('add-on [%s] to have been purchased with caller reference [%s]', $addonKey, $callerReference ?? 'none'),
            'no purchase of that add-on by that owner was recorded at all',
            'the caller references seen were',
            $seen,
        );
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
        // Deliberately not receivable. A fake account that claimed all three capabilities would let a test
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

    /**
     * Onboarding started for this merchant, and, where they are given, with these two addresses.
     *
     * They answer different questions. The return address is where the merchant lands after onboarding, so
     * a mistake there is seen at once. The refresh address is reached only when the hosted link has expired,
     * and a page there instead of the route that mints a fresh link leaves the merchant on a button offering
     * the same dead link, which nothing reports. A null address is left out of the question.
     */
    public function assertOnboardingStarted(Model $merchant, ?string $refreshUrl = null, ?string $returnUrl = null): void
    {
        $found = false;
        $seen = [];

        foreach ($this->onboardings as $call) {
            if (! $this->sameOwner($call['merchant'], $merchant)) {
                continue;
            }

            if (($refreshUrl === null || $call['refresh'] === $refreshUrl) && ($returnUrl === null || $call['return'] === $returnUrl)) {
                $found = true;

                continue;
            }

            $seen[] = sprintf('refresh [%s], return [%s]', $call['refresh'], $call['return']);
        }

        PHPUnit::assertTrue($found, $seen === []
            ? 'Expected merchant onboarding to have started, but it did not.'
            : sprintf(
                'Expected merchant onboarding with refresh [%s], return [%s], but it started with %s.',
                $refreshUrl ?? 'any',
                $returnUrl ?? 'any',
                implode('; ', $seen),
            ));
    }

    public function assertNothingOnboarded(): void
    {
        PHPUnit::assertSame([], $this->onboardings, 'Expected no merchant onboarding to have started, but at least one did.');
    }

    /**
     * The gate was asked about this merchant and said no.
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

    /**
     * A lifecycle action for the owner, held to the merchant scope it ran under when the caller names one.
     *
     * The scope was recorded from the start and never read back, so a member with subscriptions at two
     * creators could have the wrong one canceled and the test would still pass. An optional parameter is
     * enough here, unlike the coupon on assertSubscribeStartedWithCoupon(): null has one meaning left, "do
     * not check", because the platform's own subscription is spelled MerchantScope::platform(). A recorded
     * null counts as that platform, the scope a null merchant collapses to everywhere else in the package.
     */
    private function assertLifecycle(Model $owner, string $action, ?MerchantScope $merchant = null, ?string $type = null): void
    {
        $found = false;
        $seen = [];

        foreach ($this->lifecycle as $call) {
            if (! $this->sameOwner($call['owner'], $owner) || $call['action'] !== $action) {
                continue;
            }

            $recorded = ($call['merchant'] ?? MerchantScope::platform())->uid();
            $recordedType = $call['type'] ?? Subscription::TYPE_DEFAULT;

            if ((! $merchant instanceof MerchantScope || $recorded === $merchant->uid()) && ($type === null || $recordedType === $type)) {
                $found = true;

                continue;
            }

            $seen[] = $recorded.($type === null ? '' : ' on '.$recordedType);
        }

        if (! $merchant instanceof MerchantScope && $type === null) {
            PHPUnit::assertTrue($found, "Expected the subscription action [{$action}] for the owner, but it did not happen.");

            return;
        }

        // The scopes it did run under go in the message, for the reason the coupon assertion gives: the fake
        // is holding the answer, and a bare "it did not happen" sends the reader off to add a dump.
        PHPUnit::assertTrue($found, sprintf(
            'Expected the subscription action [%s] for the owner%s%s, but %s.',
            $action,
            $merchant instanceof MerchantScope ? ' under merchant ['.$merchant->uid().']' : '',
            $type === null ? '' : ' on the ['.$type.'] contract',
            $seen === []
                ? 'that action did not happen for the owner at all'
                : 'it happened under ['.implode(', ', $seen).']',
        ));
    }

    private function assertNoLifecycle(Model $owner, string $action, ?MerchantScope $merchant = null, ?string $type = null): void
    {
        $matching = [];
        $seen = [];

        foreach ($this->lifecycle as $call) {
            if (! $this->sameOwner($call['owner'], $owner)) {
                continue;
            }

            $recorded = ($call['merchant'] ?? MerchantScope::platform())->uid();
            $recordedType = $call['type'] ?? Subscription::TYPE_DEFAULT;
            $seen[] = $call['action'].' under ['.$recorded.']'.($type === null ? '' : ' on ['.$recordedType.']');

            if ($call['action'] === $action
                && (! $merchant instanceof MerchantScope || $recorded === $merchant->uid())
                && ($type === null || $recordedType === $type)) {
                $matching[] = $recorded;
            }
        }

        $where = ($merchant instanceof MerchantScope ? ' under merchant ['.$merchant->uid().']' : '')
            .($type === null ? '' : ' on the ['.$type.'] contract');

        PHPUnit::assertSame([], $matching, "Expected no subscription action [{$action}] for the owner{$where}, but the owner saw: ".implode(', ', $seen).'.');
    }

    /**
     * The last step of a value-carrying assertion. A failure names the values the matching calls did carry, or says
     * that there was no matching call at all, the two answers every value-carrying assertion here gives.
     *
     * @param  list<string>  $seen
     */
    private function assertFound(bool $found, string $expected, string $nothing, string $seenWere, array $seen): void
    {
        PHPUnit::assertTrue($found, sprintf(
            'Expected %s, but %s.',
            $expected,
            $seen === [] ? $nothing : $seenWere.' ['.implode(', ', $seen).']',
        ));
    }

    /** Whether the caller asked for the buyer's tax ID, as a failure message names it. */
    private function taxIdCollection(?bool $collect): string
    {
        return match ($collect) {
            true => 'asked',
            false => 'not asked',
            null => 'the configured default',
        };
    }

    /** Two surveys alike: the same reason and the same detail. */
    private function sameSurvey(?CancellationSurvey $a, ?CancellationSurvey $b): bool
    {
        return $a?->reason === $b?->reason && $a?->detail === $b?->detail;
    }

    /** A survey as a failure message names it. */
    private function survey(?CancellationSurvey $survey): string
    {
        if (! $survey instanceof CancellationSurvey) {
            return 'none';
        }

        return $survey->detail === null ? $survey->reason->value : $survey->reason->value.': '.$survey->detail;
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
