<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Whether a billing owner buys as a business.
 *
 * The package cannot tell. What makes an owner a business is something the application has established: a company
 * account, a registered trader, an advertiser it approved. An offer whose `buyers` is `business` is shown and sold
 * only to an owner this answers true for.
 */
interface IdentifiesBusinessBuyers
{
    public function isBusiness(Model $owner): bool;
}
