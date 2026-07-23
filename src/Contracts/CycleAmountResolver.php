<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\Models\SubscriptionItem;
use Pushery\Billing\ValueObjects\BillingPeriod;
use Pushery\Billing\ValueObjects\Money;

/**
 * What one subscription line costs for one billing cycle.
 *
 * This exists because only Stripe can price usage remotely. Its meters rate the events and the amount comes
 * back from the provider; a local-engine driver has no such thing and has to arrive at the number itself,
 * from its own usage counters and the catalog's prices. Both answer the same question, so both answer it
 * through this contract and everything upstream — invoicing, the cycle run, previews — stays driver-neutral.
 *
 * Rebind it to price usage the application's own way. A line can also name its own resolver in its
 * `preprocessor` column, which the default implementation honors: that is the per-line escape hatch, for
 * when one metered dimension is priced differently from the rest.
 *
 * Implementations must not return zero for a line they cannot price. Zero is a real amount — a metered line
 * with no usage costs nothing — so a resolver that uses it to mean "I do not know" turns an unpriced cycle
 * into a settled one that bills nothing, and no test or log will show it.
 */
interface CycleAmountResolver
{
    public function resolve(SubscriptionItem $item, BillingPeriod $period): Money;
}
