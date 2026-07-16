<?php

declare(strict_types=1);

namespace Pushery\Billing\Proration;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\ProrationStrategy;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\Plan;

/**
 * The Stripe-era proration strategy: the provider prorates on its own side, so there is nothing to
 * compute locally. previewSwap returns null (the provider's upcoming-invoice preview is authoritative,
 * and the UI degrades rather than fabricating a figure) and applySwap does nothing — the proration is
 * booked by the provider when the swap is executed against it. The credit-balance drivers replace this
 * with a strategy backed by the ProrationCalculator.
 */
final readonly class DelegatedProrationStrategy implements ProrationStrategy
{
    public function previewSwap(Model $billable, Plan $newPlan): ?Money
    {
        return null;
    }

    public function applySwap(Model $billable, Plan $newPlan): void
    {
        // The provider books the proration itself; nothing to apply locally.
    }
}
