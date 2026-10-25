<?php

declare(strict_types=1);

namespace Pushery\Billing\Support\Concerns;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Exceptions\BalanceSubscriptionRefused;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\ValueObjects\MerchantScope;

/**
 * The pause and the scheduled change of tier of a driver that offers them, refused for a subscription paid from an
 * internal balance and handed to the driver for every other one.
 *
 * Used beside `RoutesBalanceSubscriptions`, whose lookup it shares, by the actions of a driver that implements
 * `PausesSubscriptions` and `AppliesScheduledSwaps`.
 */
trait RoutesBalanceSubscriptionPauses
{
    public function pause(Model $billable, ?MerchantScope $merchant = null, ?string $type = null): void
    {
        if ($this->paidFromABalance($billable, $merchant, $type) instanceof Subscription) {
            throw BalanceSubscriptionRefused::cannotChange('be paused');
        }

        $this->inner->pause($billable, $merchant, $type);
    }

    public function unpause(Model $billable, ?MerchantScope $merchant = null, ?string $type = null): void
    {
        // A subscription paid from a balance is never paused, so there is nothing to take back.
        if ($this->paidFromABalance($billable, $merchant, $type) instanceof Subscription) {
            return;
        }

        $this->inner->unpause($billable, $merchant, $type);
    }

    public function applyScheduledSwap(Model $billable, string $tierKey, bool $prorate = true, ?MerchantScope $merchant = null, ?string $type = null): void
    {
        if ($this->paidFromABalance($billable, $merchant, $type) instanceof Subscription) {
            throw BalanceSubscriptionRefused::cannotChange('change its tier');
        }

        $this->inner->applyScheduledSwap($billable, $tierKey, $prorate, $merchant, $type);
    }
}
