<?php

declare(strict_types=1);

namespace Pushery\Billing\Support\Concerns;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\InternalBalance;
use Pushery\Billing\Enums\BillingInterval;
use Pushery\Billing\Exceptions\BalanceSubscriptionRefused;
use Pushery\Billing\Exceptions\EligibilityDenied;
use Pushery\Billing\Marketplace\BalanceSubscriptions;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\ValueObjects\CancellationSurvey;
use Pushery\Billing\ValueObjects\MerchantScope;

/**
 * The driver's subscription actions, with a subscription paid from an internal balance handed to `BalanceSubscriptions`.
 *
 * Every screen and listener that ends or resumes a subscription asks the bound `SubscriptionActions` by owner,
 * merchant and type. A subscription paid from a balance has no provider subscription for the driver to act on, so the
 * row is read first: one paid from a balance goes to `BalanceSubscriptions`, every other one to the driver as before.
 * A change of tier is refused for it, because such a subscription changes its tier by ending and subscribing again.
 *
 * The using class holds the driver's actions in `$inner`, a resolver of `BalanceSubscriptions` in `$balances` and the
 * eligibility gate in `$eligibility`, which a change of tier asks before anything else, as every seam where a payment
 * begins does.
 */
trait RoutesBalanceSubscriptions
{
    public function cancel(Model $billable, ?CancellationSurvey $survey = null, ?MerchantScope $merchant = null, ?string $type = null): void
    {
        $subscription = $this->paidFromABalance($billable, $merchant, $type);

        if ($subscription instanceof Subscription) {
            $this->balanceSubscriptions()->cancel($subscription);

            return;
        }

        $this->inner->cancel($billable, $survey, $merchant, $type);
    }

    public function cancelAt(Model $billable, CarbonInterface $endsAt, ?MerchantScope $merchant = null, ?string $type = null): void
    {
        $subscription = $this->paidFromABalance($billable, $merchant, $type);

        if ($subscription instanceof Subscription) {
            $this->balanceSubscriptions()->cancelAt($subscription, $endsAt);

            return;
        }

        $this->inner->cancelAt($billable, $endsAt, $merchant, $type);
    }

    public function resume(Model $billable, ?MerchantScope $merchant = null, ?string $type = null): void
    {
        $subscription = $this->paidFromABalance($billable, $merchant, $type);

        if ($subscription instanceof Subscription) {
            $this->balanceSubscriptions()->resume($subscription);

            return;
        }

        $this->inner->resume($billable, $merchant, $type);
    }

    public function cancelNow(Model $billable, ?MerchantScope $merchant = null, ?string $type = null): void
    {
        $subscription = $this->paidFromABalance($billable, $merchant, $type);

        if ($subscription instanceof Subscription) {
            $this->balanceSubscriptions()->cancelNow($subscription);

            return;
        }

        $this->inner->cancelNow($billable, $merchant, $type);
    }

    public function swap(Model $billable, string $tierKey, bool $prorate = true, ?MerchantScope $merchant = null, ?string $type = null, ?BillingInterval $interval = null): void
    {
        // An owner who may not transact is told that first, whichever side holds the subscription.
        if (! $this->eligibility->check($billable)) {
            throw EligibilityDenied::forMoneyMovement();
        }

        if ($this->paidFromABalance($billable, $merchant, $type) instanceof Subscription) {
            throw BalanceSubscriptionRefused::cannotChange('change its tier');
        }

        $this->inner->swap($billable, $tierKey, $prorate, $merchant, $type, $interval);
    }

    /** The balance subscriptions, resolved only when an action reaches one. */
    private function balanceSubscriptions(): BalanceSubscriptions
    {
        return ($this->balances)();
    }

    /** The owner's subscription with the merchant, where it is paid from an internal balance, and null otherwise. */
    private function paidFromABalance(Model $billable, ?MerchantScope $merchant, ?string $type): ?Subscription
    {
        $subscription = Subscription::model()::query()
            ->forOwner($billable)
            ->ofType($type)
            ->forMerchant($merchant)
            ->latest('id')
            ->first();

        return $subscription instanceof Subscription && $subscription->provider === InternalBalance::PROVIDER ? $subscription : null;
    }
}
