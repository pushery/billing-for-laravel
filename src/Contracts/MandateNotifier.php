<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Notifies an owner that a payment method the package could charge off-session was removed — a card
 * detached, a SEPA mandate revoked. It matters BEFORE the next renewal: without a usable method the
 * renewal charge fails, and the reactive dunning notice then arrives after the failure. A confirmation
 * that a method was removed (whether the customer did it or not) lets them re-add one in time, and is a
 * useful security signal either way.
 *
 * A SEPARATE seam from DunningNotifier — adding it never touches that published one-method contract, so a
 * consumer that implemented DunningNotifier keeps working. The package ships a Laravel-notification
 * default; a consumer can swap in its own delivery.
 */
interface MandateNotifier
{
    public function mandateRevoked(Model $owner, string $mandateReference): void;
}
