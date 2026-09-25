<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\HostedPortal;

/**
 * No hosted billing portal: the driver runs none, so there is no address to send an owner to.
 *
 * The portal route answers 404 on a null address, which is the honest answer for a driver whose capabilities say
 * it has no portal. The account screens hide the link on the same capability, so this is what a direct request
 * to the route meets.
 */
final readonly class NullHostedPortal implements HostedPortal
{
    public function url(Model $billable): ?string
    {
        return null;
    }
}
