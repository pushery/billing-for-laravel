<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * Whether a seller's record is good enough to act on.
 *
 * `Incomplete` is reserved for missing fields a law actually requires. A record missing only
 * precautionary fields is COMPLETE, and that distinction is load-bearing: an escalation that withheld
 * somebody's money over data nobody is entitled to demand would be a worse failure than the gap it was
 * chasing.
 */
enum SellerRecordCompleteness: string
{
    case Complete = 'complete';

    /** A required field is missing. This is what an escalation may act on — and the only thing. */
    case Incomplete = 'incomplete';

    /** It was complete once, and the information has gone stale. */
    case Expired = 'expired';
}
