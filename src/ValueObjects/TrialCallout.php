<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Pushery\Billing\Enums\SubscriptionState;
use Pushery\Billing\Support\TrialCallouts;

/**
 * The single call-to-action an account screen shows an owner while they are on a trial — exactly one per
 * trial state, resolved by {@see TrialCallouts}. It carries the state that raised
 * it, a WireKit intent (so severity reads by color AND text), how many whole days are left (for the
 * message), and the message / CTA-label translation keys plus the hub route the action points at. The
 * message key is a `trans_choice` key so ":days day / :days days" pluralizes per locale.
 */
final readonly class TrialCallout
{
    public function __construct(
        public SubscriptionState $state,
        public string $intent,
        public int $daysLeft,
        public string $messageKey,
        public string $ctaKey,
        public string $ctaRoute,
    ) {}
}
