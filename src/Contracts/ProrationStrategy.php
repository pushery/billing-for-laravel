<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\Plan;

/**
 * How unused time is credited when a plan is swapped mid-cycle. Two implementations:
 *
 *  - DELEGATE (Stripe): defer to the provider's own proration.
 *  - CREDIT-BALANCE (Mollie/Adyen): the package computes the unused portion into a customer credit
 *    balance and offsets the next order — because those providers have no provider-side proration.
 */
interface ProrationStrategy
{
    /** The net proration amount for swapping to a new plan now, or null when it cannot be previewed. */
    public function previewSwap(Model $billable, Plan $newPlan): ?Money;

    /** Apply the proration for a swap to a new plan. */
    public function applySwap(Model $billable, Plan $newPlan): void;
}
