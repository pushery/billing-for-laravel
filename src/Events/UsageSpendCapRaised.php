<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\SerializesModels;
use Pushery\Billing\ValueObjects\Money;

/**
 * An owner's spend cap was raised: what a paused app was waiting for.
 *
 * After it, the next request the cap stops fires UsageSpendCapReached again.
 */
final readonly class UsageSpendCapRaised implements BillingDomainEvent
{
    use SerializesModels;

    public function __construct(
        public Model $owner,
        public Money $from,
        public Money $to,
    ) {}
}
