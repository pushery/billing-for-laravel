<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\SerializesModels;
use Pushery\Billing\ValueObjects\Money;

/**
 * The owner's spend cap stopped a metered request, or usage recorded past it took the spend over it.
 *
 * Fires once per billing period, and once more after the cap is raised, so an app can pause what costs money when it
 * fires and resume when UsageSpendCapRaised does. `spent` is what the period's usage past the allowance costs, held
 * units included, at the moment it fired.
 */
final readonly class UsageSpendCapReached implements BillingDomainEvent
{
    use SerializesModels;

    public function __construct(
        public Model $owner,
        public Money $cap,
        public Money $spent,
        public string $period,
    ) {}
}
