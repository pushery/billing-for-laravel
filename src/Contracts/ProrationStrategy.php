<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\Plan;

/**
 * What a plan swapped mid-cycle costs or gives back. Three implementations:
 *
 *  - DELEGATE (Stripe): defer to the provider's own proration.
 *  - ARREARS (the package's own engine, under Mollie): nothing is paid for the period in progress, so the swap
 *    changes the bill that closes it, which then charges each tier for the days it held.
 *  - CREDIT-BALANCE (a local driver that collects in advance): the package computes the unused portion of the
 *    paid period into a customer credit balance and offsets the next order.
 */
interface ProrationStrategy
{
    /** The net proration amount for swapping to a new plan now, or null when it cannot be previewed. */
    public function previewSwap(Model $billable, Plan $newPlan): ?Money;

    /** Apply the proration for a swap to a new plan. */
    public function applySwap(Model $billable, Plan $newPlan): void;
}
