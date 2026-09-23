<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Pushery\Billing\Enums\SubscriptionState;

/**
 * A single app-shell banner: the one thing the owner most needs to act on about their billing right
 * now (a failed payment, a lapsing grace period, a trial about to end). It carries the state that
 * raised it, a WireKit intent so the callout conveys severity by color AND text, the message and
 * call-to-action translation keys, and the hub route the action points at. Resolving to no banner is
 * the common case — a healthy account shows nothing.
 *
 * A notice about a contract the account screens do not manage — a merchant's subscription in a
 * marketplace, or the platform's own contract of another type — carries no call to action, so both
 * `$ctaKey` and `$ctaRoute` are null. `$merchant` names the merchant such a notice is about, when the
 * install can resolve the name; it is null for the platform's own contracts.
 */
final readonly class BannerNotice
{
    public function __construct(
        public SubscriptionState $state,
        public string $intent,
        public string $messageKey,
        public ?string $ctaKey,
        public ?string $ctaRoute,
        public ?string $merchant = null,
    ) {}
}
