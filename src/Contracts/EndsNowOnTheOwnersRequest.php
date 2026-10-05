<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\ValueObjects\MerchantScope;

/**
 * Ending a subscription now because its owner asked, which a driver that bills in arrears must not treat as
 * stopping the billing.
 *
 * {@see SubscriptionActions::cancelNow()} stops billing and bills nothing more. That is what account deletion, a
 * withdrawal and a reversed sale need, and it is also right for an owner who leaves a driver that collects each
 * period in advance, because the days they had are paid already. A driver that bills a period at its END has not
 * been paid for the days of the period in progress, and stopping the billing there would leave days that were
 * provided unbilled. Such a driver implements this as well, and the account hub's danger zone calls it instead of
 * `cancelNow()`.
 */
interface EndsNowOnTheOwnersRequest
{
    /**
     * End the subscription now, and bill the days of the period in progress that it has already had.
     *
     * The merchant scope and the contract type work as on every {@see SubscriptionActions} method.
     */
    public function endNow(Model $billable, ?MerchantScope $merchant = null, ?string $type = null): void;
}
