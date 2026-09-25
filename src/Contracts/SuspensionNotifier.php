<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\ValueObjects\Money;

/**
 * Delivers the escalating suspension warning as an owner climbs the dunning ladder, before a surface is
 * locked out. A SEPARATE seam from DunningNotifier (which fires once, on the payment failure) so that
 * adding the escalation never touches the published one-method DunningNotifier contract — a consumer
 * that implemented DunningNotifier keeps working. The package ships a Laravel-notification default; a
 * consumer can swap in its own delivery.
 */
interface SuspensionNotifier
{
    /**
     * @param  Money  $lateFee  the fee this rung added to what the owner is charged next, zero when it added
     *                          none. It is not the overdue amount: the dunning advance does not know that
     *                          figure on every driver, and printing the fee in its place asked a customer to
     *                          settle 0.00 on every rung without one.
     */
    public function suspensionWarning(Model $owner, Money $lateFee): void;
}
