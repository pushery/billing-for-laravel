<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Shopify;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\CanTransactMoney;
use Pushery\Billing\Contracts\SubscriptionActions;
use Pushery\Billing\Exceptions\EligibilityDenied;
use Pushery\Billing\Exceptions\NotPossibleOnShopify;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\ValueObjects\CancellationSurvey;
use Pushery\Billing\ValueObjects\MerchantScope;
use Throwable;

/**
 * The subscription actions Shopify's billing has, and a refusal for the two it does not.
 *
 * Shopify cancels a subscription at once and has no cancellation to a later date. So a cancellation cancels it
 * at Shopify without a credit, since the merchant paid for the period in progress, and the end is kept on the
 * local row: the merchant keeps their plan until then, and the engine of this driver ends the subscription once
 * the date has passed. The end is written before Shopify is asked, so the webhook that reports the cancellation
 * finds it, and taken back when Shopify refuses.
 *
 * A canceled subscription does not come back at Shopify, and a plan changes only through a new subscription the
 * merchant approves. `resume()` and `swap()` cannot hand back the page where the merchant does, so they refuse
 * and name `Checkout::subscribe()`, which can.
 */
final readonly class ShopifySubscriptionActions implements SubscriptionActions
{
    private const string CANCEL = <<<'GRAPHQL'
        mutation BillingSubscriptionCancel($id: ID!, $prorate: Boolean) {
          appSubscriptionCancel(id: $id, prorate: $prorate) {
            appSubscription { id status }
            userErrors { field message }
          }
        }
        GRAPHQL;

    public function __construct(
        private ShopifyAdmin $admin,
        private ShopifyShops $shops,
        private CanTransactMoney $eligibility,
    ) {}

    /** At the end of the period in progress. The survey has no field at Shopify and changes nothing here. */
    public function cancel(Model $billable, ?CancellationSurvey $survey = null, ?MerchantScope $merchant = null, ?string $type = null): void
    {
        $subscription = $this->subscriptionFor($billable, $merchant, $type);

        // A later cancellation leaves an end that was set before in place.
        if (! $subscription instanceof Subscription || $subscription->ends_at !== null) {
            return;
        }

        $this->endAt($billable, $subscription, $subscription->current_period_end ?? CarbonImmutable::now());
    }

    public function cancelAt(Model $billable, CarbonInterface $endsAt, ?MerchantScope $merchant = null, ?string $type = null): void
    {
        $subscription = $this->subscriptionFor($billable, $merchant, $type);

        if (! $subscription instanceof Subscription) {
            return;
        }

        $subscription->assertCanEndAt($endsAt);

        $this->endAt($billable, $subscription, $endsAt);
    }

    public function resume(Model $billable, ?MerchantScope $merchant = null, ?string $type = null): void
    {
        $subscription = $this->subscriptionFor($billable, $merchant, $type);

        if ($subscription instanceof Subscription && $subscription->ends_at !== null) {
            throw NotPossibleOnShopify::resume();
        }
    }

    public function cancelNow(Model $billable, ?MerchantScope $merchant = null, ?string $type = null): void
    {
        $subscription = $this->subscriptionFor($billable, $merchant, $type);

        if (! $subscription instanceof Subscription) {
            return;
        }

        $this->endAt($billable, $subscription, CarbonImmutable::now());
    }

    /**
     * Asks the eligibility gate first, as every seam where a payment begins does: an owner who may not transact is
     * told that, not where to subscribe.
     */
    public function swap(Model $billable, string $tierKey, bool $prorate = true, ?MerchantScope $merchant = null, ?string $type = null): void
    {
        if (! $this->eligibility->check($billable)) {
            throw EligibilityDenied::forMoneyMovement();
        }

        throw NotPossibleOnShopify::changeThePlan();
    }

    /** Record the end, then cancel at Shopify without a credit, and take the end back where Shopify refuses. */
    private function endAt(Model $billable, Subscription $subscription, CarbonInterface $endsAt): void
    {
        $previous = $subscription->ends_at;

        $subscription->update(['ends_at' => CarbonImmutable::instance($endsAt)]);

        try {
            $this->admin->mutate($this->shops->of($billable), self::CANCEL, [
                'id' => (string) $subscription->provider_id,
                'prorate' => false,
            ], 'appSubscriptionCancel');
        } catch (Throwable $exception) {
            $subscription->update(['ends_at' => $previous]);

            throw $exception;
        }
    }

    private function subscriptionFor(Model $billable, ?MerchantScope $merchant, ?string $type): ?Subscription
    {
        return Subscription::model()::query()
            ->forOwner($billable)
            ->ofType($type)
            ->forMerchant($merchant)
            ->where('provider', 'shopify')
            ->whereNotNull('provider_id')
            ->latest('id')
            ->first();
    }
}
