<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * A bridge to the provider's own hosted billing portal (Stripe's customer portal). It is optional: a
 * driver without one — or a billable without a provider customer — returns null, and the account hub
 * keeps the owner on its in-app screens instead. Never the primary path (the package ships full in-app
 * screens); the bridge is a convenience for apps that prefer the provider's portal.
 */
interface HostedPortal
{
    /** A short-lived URL to the provider's hosted billing portal, or null when unavailable. */
    public function url(Model $billable): ?string;
}
