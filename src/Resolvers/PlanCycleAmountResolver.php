<?php

declare(strict_types=1);

namespace Pushery\Billing\Resolvers;

use Illuminate\Contracts\Container\Container;
use Pushery\Billing\Contracts\CycleAmountResolver;
use Pushery\Billing\Exceptions\CycleAmountUnresolvable;
use Pushery\Billing\Models\SubscriptionItem;
use Pushery\Billing\ValueObjects\BillingPeriod;
use Pushery\Billing\ValueObjects\Money;

/**
 * The default cycle pricing: a fixed line costs what it says it costs, and a metered line is handed to
 * whichever resolver was named for it.
 *
 * This is the whole of the default behavior, and it is deliberately small. Most subscriptions are a flat
 * plan whose amount was known when the line was written, so pricing a cycle is reading it back. Usage is the
 * exception, and rating usage is a decision — which counters, whose allowance, what happens to a partial
 * package — that belongs to whoever is billing, not to a default.
 *
 * A line names its resolver in `preprocessor`, which is resolved from the container. That is what lets one
 * metered dimension be priced differently from another on the same subscription without rebinding anything
 * globally. A metered line with no resolver is refused rather than treated as free.
 */
final readonly class PlanCycleAmountResolver implements CycleAmountResolver
{
    public function __construct(private Container $container) {}

    public function resolve(SubscriptionItem $item, BillingPeriod $period): Money
    {
        if ($item->metered) {
            return $this->delegateMeteredLine($item, $period);
        }

        // A fixed line carries its own amount. Missing means the line was written wrong, not that the
        // cycle is free — so it is refused rather than billed as zero.
        return $item->amount() ?? throw CycleAmountUnresolvable::fixedLineHasNoAmount($item->plan_key);
    }

    private function delegateMeteredLine(SubscriptionItem $item, BillingPeriod $period): Money
    {
        $preprocessor = $item->preprocessor;

        if ($preprocessor === null || $preprocessor === '') {
            throw CycleAmountUnresolvable::noResolverForMeteredLine($item->plan_key);
        }

        $resolver = $this->container->make($preprocessor);

        if (! $resolver instanceof CycleAmountResolver) {
            throw CycleAmountUnresolvable::noResolverForMeteredLine($item->plan_key);
        }

        // A resolver that named itself would recurse until the stack gave out, and the failure would point
        // at the stack rather than at the configuration that caused it.
        if ($resolver instanceof self) {
            throw CycleAmountUnresolvable::noResolverForMeteredLine($item->plan_key);
        }

        return $resolver->resolve($item, $period);
    }
}
