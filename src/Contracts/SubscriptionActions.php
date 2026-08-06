<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\ValueObjects\CancellationSurvey;
use Pushery\Billing\ValueObjects\MerchantScope;

/**
 * The only provider-mutating subscription seam. Cancel moves to a grace period; resume is grace-only;
 * cancelNow stops billing immediately (used by account deletion). Swap performs an in-app
 * upgrade/downgrade — the superset closure that replaces delegating plan changes to a hosted portal.
 *
 * Every method takes an optional trailing merchant scope, defaulting to null — the platform, the
 * single-seller case — so every existing caller is unchanged. With a merchant given, the action addresses
 * exactly that (billable, merchant) subscription: canceling on creator A leaves the fan's subscription to
 * creator B untouched.
 */
interface SubscriptionActions
{
    /**
     * Cancel at period end (enters the grace period). The optional survey carries the owner's reason for
     * leaving; a driver passes it to the provider's native cancellation-feedback field where one exists. It
     * is purely informational — a cancellation NEVER depends on it, and a null survey is the normal case.
     */
    public function cancel(Model $billable, ?CancellationSurvey $survey = null, ?MerchantScope $merchant = null): void;

    /** Resume a subscription that is still within its grace period. */
    public function resume(Model $billable, ?MerchantScope $merchant = null): void;

    /** Cancel immediately, stopping billing now (no grace). */
    public function cancelNow(Model $billable, ?MerchantScope $merchant = null): void;

    /** Swap to another tier's plan in-app, prorating unless told otherwise. */
    public function swap(Model $billable, string $tierKey, bool $prorate = true, ?MerchantScope $merchant = null): void;
}
