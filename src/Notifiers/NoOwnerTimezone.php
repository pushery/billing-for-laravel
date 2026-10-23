<?php

declare(strict_types=1);

namespace Pushery\Billing\Notifiers;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\OwnerTimezone;

/**
 * No zone for any owner: a notice names the day of the moment as it is stored, which this package keeps in UTC.
 */
final class NoOwnerTimezone implements OwnerTimezone
{
    public function for(Model $owner): ?string
    {
        return null;
    }
}
