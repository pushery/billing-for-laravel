<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A creator's tax declaration is due for renewal, and the date its hold begins is known.
 *
 * Dispatched twice for one declaration at most: when the year turns and the renewal falls due, and once
 * more when the declaration is about to run out. `lastReminder` tells the two apart. When both fall on the
 * same run, one event goes out, marked as the last.
 *
 * `holdFrom` is the moment the declaration stops answering. From then the creator can neither sell nor be
 * paid until they declare again, and a notice that leaves out the date gives them nothing to act on.
 */
final readonly class CreatorReattestationDue implements BillingDomainEvent
{
    public function __construct(
        public Model $merchant,
        public CarbonImmutable $holdFrom,
        public bool $lastReminder,
    ) {}
}
