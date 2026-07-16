<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\CustomerRegistry;

/**
 * The default: erasing an owner touches no provider at all.
 *
 * Deleting a customer at the provider is irreversible and cancels their live subscriptions, so it is never
 * something a package should do to an app that did not ask for it. An app that wants the provider-side
 * customer gone too turns on `billing.erasure.forget_customer`, and the active driver's registry is bound
 * in its place.
 */
final class NullCustomerRegistry implements CustomerRegistry
{
    public function forget(Model $billable): void
    {
        // Nothing to forget: the provider is not ours to touch unless the app says so.
    }
}
