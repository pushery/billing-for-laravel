<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Closure;
use Pushery\Billing\Contracts\CanTransactMoney;
use Pushery\Billing\Contracts\SubscriptionActions;
use Pushery\Billing\Marketplace\BalanceSubscriptions;
use Pushery\Billing\Support\Concerns\RoutesBalanceSubscriptions;

/**
 * The actions of a driver that only ends, resumes and changes subscriptions, with a subscription paid from an
 * internal balance handed to `BalanceSubscriptions`.
 */
final readonly class BalanceRoutedSubscriptionActions implements SubscriptionActions
{
    use RoutesBalanceSubscriptions;

    /** @param  Closure(): BalanceSubscriptions  $balances */
    public function __construct(
        private SubscriptionActions $inner,
        private Closure $balances,
        private CanTransactMoney $eligibility,
    ) {}
}
