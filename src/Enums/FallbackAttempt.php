<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * Where a cycle stands with the owner's fallback mandate, the one charged once when the default could not pay.
 *
 * An order that never needed the fallback carries no attempt at all. The three states follow one another and
 * never go back, so a cycle tries the fallback at most once, whatever happens to the dunning retries after it.
 */
enum FallbackAttempt: string
{
    /** The default could not pay and the fallback is next: the following run charges it, before any dunning. */
    case Due = 'due';

    /** The fallback was charged, and the payment has not been refused. */
    case Made = 'made';

    /** The fallback could not pay either, or was gone by the time it was due: dunning has the cycle now. */
    case Refused = 'refused';
}
