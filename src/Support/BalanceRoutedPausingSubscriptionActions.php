<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Closure;
use Pushery\Billing\Contracts\AppliesScheduledSwaps;
use Pushery\Billing\Contracts\CanTransactMoney;
use Pushery\Billing\Contracts\PausesSubscriptions;
use Pushery\Billing\Contracts\SubscriptionActions;
use Pushery\Billing\Marketplace\BalanceSubscriptions;
use Pushery\Billing\Support\Concerns\RoutesBalanceSubscriptionPauses;
use Pushery\Billing\Support\Concerns\RoutesBalanceSubscriptions;

/**
 * The actions of a driver that also pauses subscriptions and applies scheduled changes of tier, with a subscription
 * paid from an internal balance handed to `BalanceSubscriptions`. The two abilities stay declared, so a screen that
 * offers a pause still offers it for every other subscription.
 */
final readonly class BalanceRoutedPausingSubscriptionActions implements AppliesScheduledSwaps, PausesSubscriptions, SubscriptionActions
{
    use RoutesBalanceSubscriptionPauses;
    use RoutesBalanceSubscriptions;

    /** @param  Closure(): BalanceSubscriptions  $balances */
    public function __construct(
        private SubscriptionActions&PausesSubscriptions&AppliesScheduledSwaps $inner,
        private Closure $balances,
        private CanTransactMoney $eligibility,
    ) {}
}
