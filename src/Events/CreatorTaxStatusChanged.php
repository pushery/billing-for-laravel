<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Enums\CreatorTaxStatus;
use Pushery\Billing\Enums\CreatorTaxStatusSource;

/**
 * A creator's tax standing moved.
 *
 * It carries the moment the new status takes effect, not the moment it was recorded, because the two are
 * routinely different and the consumers care about the first: a change effective in January is a
 * retroactive one, and what has to happen next depends entirely on how far back it reaches.
 *
 * The notice to the creator and the payout hold listen here instead of knowing where a status is stored.
 */
final readonly class CreatorTaxStatusChanged implements BillingDomainEvent
{
    public function __construct(
        public Model $merchant,
        public CreatorTaxStatus $previous,
        public CreatorTaxStatus $current,
        public CarbonImmutable $effectiveFrom,
        public CreatorTaxStatusSource $source,
    ) {}
}
