<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\SerializesModels;

/**
 * An owner's subscription was paused: nothing is collected, and it grants no access until it is taken up again.
 *
 * Dispatched by the actions that paused it, once the driver accepted the pause, so an application can hang its own
 * lock or notice on it. Provider-neutral, dispatched through Laravel's dispatcher.
 */
final readonly class SubscriptionPaused implements BillingDomainEvent
{
    use SerializesModels;

    public function __construct(
        public Model $owner,
    ) {}
}
