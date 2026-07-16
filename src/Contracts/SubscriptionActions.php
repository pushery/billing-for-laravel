<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * The only provider-mutating subscription seam. Cancel moves to a grace period; resume is grace-only;
 * cancelNow stops billing immediately (used by account deletion). Swap performs an in-app
 * upgrade/downgrade — the superset closure that replaces delegating plan changes to a hosted portal.
 */
interface SubscriptionActions
{
    /** Cancel at period end (enters the grace period). */
    public function cancel(Model $billable): void;

    /** Resume a subscription that is still within its grace period. */
    public function resume(Model $billable): void;

    /** Cancel immediately, stopping billing now (no grace). */
    public function cancelNow(Model $billable): void;

    /** Swap to another tier's plan in-app, prorating unless told otherwise. */
    public function swap(Model $billable, string $tierKey, bool $prorate = true): void;
}
