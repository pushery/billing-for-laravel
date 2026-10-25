<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\AppliesScheduledSwaps;
use Pushery\Billing\Contracts\CanTransactMoney;
use Pushery\Billing\Contracts\EndsNowOnTheOwnersRequest;
use Pushery\Billing\Contracts\PausesSubscriptions;
use Pushery\Billing\Contracts\SubscriptionActions;
use Pushery\Billing\Marketplace\BalanceSubscriptions;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\Support\Concerns\RoutesBalanceSubscriptionPauses;
use Pushery\Billing\Support\Concerns\RoutesBalanceSubscriptions;
use Pushery\Billing\ValueObjects\MerchantScope;

/**
 * The actions of the local engine, which also end a subscription now at the owner's request, with a subscription
 * paid from an internal balance handed to `BalanceSubscriptions`. Every ability of the local actions stays declared.
 */
final readonly class BalanceRoutedLocalSubscriptionActions implements AppliesScheduledSwaps, EndsNowOnTheOwnersRequest, PausesSubscriptions, SubscriptionActions
{
    use RoutesBalanceSubscriptionPauses;
    use RoutesBalanceSubscriptions;

    /** @param  Closure(): BalanceSubscriptions  $balances */
    public function __construct(
        private SubscriptionActions&PausesSubscriptions&AppliesScheduledSwaps&EndsNowOnTheOwnersRequest $inner,
        private Closure $balances,
        private CanTransactMoney $eligibility,
    ) {}

    public function endNow(Model $billable, ?MerchantScope $merchant = null, ?string $type = null): void
    {
        $subscription = $this->paidFromABalance($billable, $merchant, $type);

        // Paid in advance, so the owner has had nothing that is still owed: it ends now.
        if ($subscription instanceof Subscription) {
            $this->balanceSubscriptions()->cancelNow($subscription);

            return;
        }

        $this->inner->endNow($billable, $merchant, $type);
    }
}
