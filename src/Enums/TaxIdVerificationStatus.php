<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * What a tax authority's register said about a tax ID, as the provider reported it.
 */
enum TaxIdVerificationStatus: string
{
    /** Asked, and not answered yet. */
    case Pending = 'pending';

    /** The register knows the number. */
    case Verified = 'verified';

    /** The register does not know the number. A sale reverse-charged under it can owe the tax after all. */
    case Unverified = 'unverified';

    /** The provider could not have the number checked. No verdict follows from it. */
    case Unavailable = 'unavailable';
}
