<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\SerializesModels;

/**
 * An owner's paused subscription was taken up again and is collected from now.
 *
 * Carries how long it rested, in seconds: on the package's own engine that is the time added to the period the
 * subscription is in, and null where the driver keeps its own periods and the pause adds nothing to them.
 */
final readonly class SubscriptionUnpaused implements BillingDomainEvent
{
    use SerializesModels;

    public function __construct(
        public Model $owner,
        public ?int $pausedSeconds = null,
    ) {}
}
