<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\ValueObjects\MerchantScope;

/**
 * Applies a plan change that was scheduled earlier, at the moment it comes due.
 *
 * A downgrade waits for the period end, and `ScheduledSwapRunner` performs it from the scheduler, where nobody is
 * acting. `SubscriptionActions::swap()` asks the eligibility gate first, and a gate that answers for the person
 * acting refuses at that moment a change it allowed when the customer made it. An application whose gate needs an
 * acting person would refuse every scheduled change, and the customer would go on paying the tier they left.
 *
 * The gate was asked when the change was scheduled: the account screen asks it before it records the change, and
 * an application that schedules a change itself decides who may. A driver whose `swap()` asks the gate implements
 * this as the same swap without asking it again.
 */
interface AppliesScheduledSwaps
{
    public function applyScheduledSwap(Model $billable, string $tierKey, bool $prorate = true, ?MerchantScope $merchant = null, ?string $type = null): void;
}
