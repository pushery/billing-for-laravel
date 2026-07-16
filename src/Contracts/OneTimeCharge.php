<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\ValueObjects\ClientIntent;

/**
 * A first-class, subscription-independent one-time purchase (an add-on / top-up). Returns a
 * driver-shaped payload the front-end completes; the credit effect is applied once per session by the
 * webhook backbone, and what the credit grants is project-defined.
 */
interface OneTimeCharge
{
    public function purchase(Model $billable, string $addonKey): ClientIntent;
}
