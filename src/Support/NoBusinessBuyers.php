<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\IdentifiesBusinessBuyers;

/**
 * The default: no owner is a business until the application says which ones are.
 *
 * An offer only businesses may buy is then offered to nobody, which is the safe side of a rule the package cannot
 * check itself. `billing:doctor` says so when such an offer is configured.
 */
final class NoBusinessBuyers implements IdentifiesBusinessBuyers
{
    public function isBusiness(Model $owner): bool
    {
        return false;
    }
}
