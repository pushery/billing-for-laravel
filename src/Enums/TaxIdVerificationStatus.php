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

    /**
     * The customer removed the number. Recorded by this package when the provider reports the deletion, never a
     * register's answer: a verdict the number carried before stops counting for the sales after it.
     */
    case Removed = 'removed';

    /**
     * Whether the answer settles if the number counts. `verified`, `unverified` and `removed` do. A pending or
     * unavailable answer says the register was not heard, so it neither confirms a number nor takes a confirmation
     * back.
     */
    public function decides(): bool
    {
        return match ($this) {
            self::Verified, self::Unverified, self::Removed => true,
            self::Pending, self::Unavailable => false,
        };
    }
}
