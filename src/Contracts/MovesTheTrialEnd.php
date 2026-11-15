<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Exceptions\TrialEndNotMovable;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\ValueObjects\MerchantScope;

/**
 * Move the end of a subscription's trial, and the first charge with it.
 *
 * A sibling of `SubscriptionActions` rather than a method on it: that contract is implemented outside the package, and
 * a method appended to it is a fatal error in code the package does not own.
 *
 * Bound where the package's own engine bills the cycles, which is the Mollie driver: there the end of a trial is the
 * moment of the first run, kept on the subscription. Ask the container with `app()->bound(MovesTheTrialEnd::class)`
 * before offering it.
 */
interface MovesTheTrialEnd
{
    /**
     * Move the end of the subscription's trial to another moment, and its first run with it.
     *
     * The end of the trial, the end of the first period and the moment of the first run move together, so the run that
     * charges the first paid period, or closes the free one, comes at the new end, and the reminder before the end of a
     * trial names the new day. Only a subscription still in its trial, whose first run is not yet due, can be moved, and
     * only to a moment ahead of now. The merchant scope and the contract type address the subscription as they do on
     * `SubscriptionActions`.
     *
     * @throws TrialEndNotMovable when the billable has no subscription in its trial here, or the moment is not ahead of now
     */
    public function moveTheTrialEnd(Model $billable, DateTimeInterface $to, ?MerchantScope $merchant = null, ?string $type = null): Subscription;
}
