<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * How somebody came to own a work.
 *
 * A subscription is deliberately NOT one of these. Subscription access is a STATE that begins and ends with
 * the subscription, read live from the subscription row; ownership of a bought work is a FACT that outlives
 * everything, including the creator's account. Persisting a subscription as a grant would freeze a state as
 * a fact, and the first cancellation would leave a row that says somebody owns what they only ever rented.
 */
enum GrantSource: string
{
    /** Bought, by the person who owns it. */
    case Purchase = 'purchase';

    /** Bought by somebody else FOR this person — the purchaser is recorded separately from the owner. */
    case Gift = 'gift';

    /** Given by the platform or the creator: a review copy, a goodwill grant, a prize. */
    case Comp = 'comp';

    /** Came with a bundle; the bundle it came with is recorded so the group stays visible. */
    case Bundle = 'bundle';
}
