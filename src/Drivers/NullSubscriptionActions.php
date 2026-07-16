<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\SubscriptionActions;

/**
 * The no-op SubscriptionActions bound when billing is disabled (`billing.enabled=false`). A clone without
 * billing must present a clean façade rather than a provider error: there is no account-hub UI to reach these
 * in disabled mode, so they exist only so a stray resolve-and-call never throws. Every mutation is a silent,
 * controlled no-op.
 */
final class NullSubscriptionActions implements SubscriptionActions
{
    public function cancel(Model $billable): void {}

    public function resume(Model $billable): void {}

    public function cancelNow(Model $billable): void {}

    public function swap(Model $billable, string $tierKey, bool $prorate = true): void {}
}
