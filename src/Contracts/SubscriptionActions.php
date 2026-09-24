<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Pushery\Billing\Consumer\ProratedCancellation;
use Pushery\Billing\ValueObjects\CancellationSurvey;
use Pushery\Billing\ValueObjects\MerchantScope;

/**
 * The only provider-mutating subscription seam. Cancel moves to a grace period; cancelAt ends inside the
 * period instead; resume is grace-only; cancelNow stops billing immediately (used by account deletion).
 * Swap performs an in-app upgrade/downgrade — the superset closure that replaces delegating plan changes to
 * a hosted portal.
 *
 * Every method takes an optional trailing merchant scope, defaulting to null — the platform, the
 * single-seller case — so every existing caller is unchanged. With a merchant given, the action addresses
 * exactly that (billable, merchant) subscription: canceling on creator A leaves the fan's subscription to
 * creator B untouched.
 *
 * Every method also takes an optional contract type, last. The uniqueness of a subscription is (owner,
 * type, merchant), so a sponsorship beside a subscription at the same creator is a second row, and an
 * action that named only the merchant would reach the default one: an orderly cancellation of the
 * sponsorship would end the wrong contract, or none. Null is the default type, so every existing caller is
 * unchanged.
 */
interface SubscriptionActions
{
    /**
     * Cancel at period end (enters the grace period). The optional survey carries the owner's reason for
     * leaving; a driver passes it to the provider's native cancellation-feedback field where one exists. It
     * is purely informational — a cancellation NEVER depends on it, and a null survey is the normal case.
     */
    public function cancel(Model $billable, ?CancellationSurvey $survey = null, ?MerchantScope $merchant = null, ?string $type = null): void;

    /**
     * Cancel to a moment inside the period in progress, rather than at its end.
     *
     * The subscription runs until `$endsAt` and ends there. Nothing is refunded here: the unused rest of a
     * prepaid period is paid back by {@see ProratedCancellation}, which calls this first. A cancellation like
     * this is final, because that rest may have been refunded: `resume()` refuses it, and a later `cancel()`
     * leaves the earlier end in place.
     *
     * A moment that has passed is refused, because ending now is `cancelNow()`. A moment after the period end
     * is refused too, because that is `cancel()`, and a driver that bills a period at its end cannot bill part
     * of the next one.
     *
     * @throws InvalidArgumentException when `$endsAt` has passed or lies after the end of the period in progress
     */
    public function cancelAt(Model $billable, CarbonInterface $endsAt, ?MerchantScope $merchant = null, ?string $type = null): void;

    /**
     * Resume a subscription that is still within its grace period.
     *
     * @throws InvalidArgumentException when the subscription was canceled to a moment inside its period
     */
    public function resume(Model $billable, ?MerchantScope $merchant = null, ?string $type = null): void;

    /** Cancel immediately, stopping billing now (no grace). Account deletion ends every contract this way. */
    public function cancelNow(Model $billable, ?MerchantScope $merchant = null, ?string $type = null): void;

    /** Swap to another tier's plan in-app, prorating unless told otherwise. */
    public function swap(Model $billable, string $tierKey, bool $prorate = true, ?MerchantScope $merchant = null, ?string $type = null): void;
}
