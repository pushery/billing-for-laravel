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
    /**
     * @param  ?string  $declarationReference  the key the package minted for the buyer's pre-purchase
     *                                         declarations, to be carried to the provider as opaque
     *                                         metadata and handed back on the webhook. Null on every
     *                                         install with no consumer-rights profile, and a driver that
     *                                         gets null must send exactly the payload it sent before this
     *                                         parameter existed -- Mode S is a byte-identity promise, not
     *                                         a behavioral one.
     */
    public function purchase(Model $billable, string $addonKey, ?string $declarationReference = null): ClientIntent;
}
