<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * The time zone a billing owner reads dates in.
 *
 * The package stores every moment in UTC, and a notice that names the last day of a subscription or a trial names
 * the day of the moment it is handed. For an owner far from UTC that is the wrong day whenever the moment falls near
 * midnight: a subscription that ends at 22:00 UTC ends on the next day in Tokyo. Bind an implementation that answers
 * with the owner's zone, and the notices the package's own notifier sends name the day in it.
 *
 * It is asked once per notice, with the owner the notice goes to.
 */
interface OwnerTimezone
{
    /**
     * The owner's zone as an identifier such as `Asia/Tokyo`, or null to name the day of the moment as it is stored.
     * An answer that is no time zone identifier is reported and treated as null, so the notice still goes out.
     */
    public function for(Model $owner): ?string;
}
