<?php

declare(strict_types=1);

namespace Pushery\Billing\Proration;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\ProrationStrategy;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\Plan;

/**
 * Proration for the package's own engine, chosen by when the subscription is collected.
 *
 * Each subscription keeps the timing it started with, so one install can hold both kinds at once: those started
 * before the setting changed, and those after. A subscription collected in advance squares the rest of its paid
 * period at once ({@see InAdvanceProrationStrategy}); one collected in arrears prices the change into the bill that
 * closes the period ({@see ArrearsProrationStrategy}).
 */
final readonly class LocalProrationStrategy implements ProrationStrategy
{
    public function __construct(
        private ArrearsProrationStrategy $arrears,
        private InAdvanceProrationStrategy $advance,
    ) {}

    public function previewSwap(Model $billable, Plan $newPlan): ?Money
    {
        return $this->strategyFor($billable)->previewSwap($billable, $newPlan);
    }

    public function applySwap(Model $billable, Plan $newPlan): void
    {
        $this->strategyFor($billable)->applySwap($billable, $newPlan);
    }

    /** The strategy for the owner's default subscription at the platform, the one the account hub swaps. */
    private function strategyFor(Model $billable): ProrationStrategy
    {
        $subscription = Subscription::model()::query()
            ->forOwner($billable)
            ->forMerchant(null)
            ->ofDefaultType()
            ->latest('id')
            ->first();

        return $subscription instanceof Subscription && $subscription->collectsInAdvance() ? $this->advance : $this->arrears;
    }
}
