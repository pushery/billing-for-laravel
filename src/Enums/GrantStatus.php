<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * Whether a grant still grants.
 *
 * There is no soft delete here, and that is the point: a withdrawn grant is REVOKED, with a reason and a
 * date, because "why can this person no longer read what they bought" is a question somebody will ask and a
 * deleted row cannot answer. Retention and the document trail are separate concerns with their own rules.
 */
enum GrantStatus: string
{
    case Active = 'active';

    /** Taken back, for a reason the RevokeReason enum names. Never silently. */
    case Revoked = 'revoked';

    /** A rental or window that ran out on its own — nobody took anything back. */
    case Expired = 'expired';

    /** Held, reversibly: a dispute under review, an account frozen. Not the same as revoked. */
    case Suspended = 'suspended';
}
