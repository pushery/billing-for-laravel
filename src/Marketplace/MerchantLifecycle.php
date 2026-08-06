<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Pushery\Billing\Enums\MerchantStatus;
use Pushery\Billing\Events\MerchantRoutingReinstated;
use Pushery\Billing\Events\MerchantRoutingSuspended;
use Pushery\Billing\Events\MerchantTerminated;
use Pushery\Billing\Models\MerchantAccount;

/**
 * The one place a merchant's standing with the platform changes.
 *
 * Every transition here is idempotent and only fires an event when something actually moved. That is not a
 * nicety: the capability reports that drive most of these arrive several times during a single provider-side
 * verification, and a suspension that re-emitted on each one would have anything downstream — a notice to
 * the merchant, a subscription policy, an operator alert — firing repeatedly for one event in the world.
 *
 * The transitions are guarded rather than free. A terminated merchant is never reinstated by a capability
 * report, because a provider keeps reporting healthy capabilities for an account long after its owner
 * disconnected it from this platform; obeying that report would resume routing into a relationship that no
 * longer exists. Termination is therefore one-way from here — it is undone by onboarding again, not by a
 * webhook.
 */
final readonly class MerchantLifecycle
{
    public function __construct(private Dispatcher $events) {}

    /**
     * Stop routing new money, keeping the door open.
     *
     * A no-op for a merchant who is already suspended or terminated: the first reason is the one that
     * explains the state, and overwriting it with a later, vaguer one loses why it happened.
     */
    public function suspend(MerchantAccount $account, string $reason): bool
    {
        if ($account->status !== MerchantStatus::Active) {
            return false;
        }

        $this->moveTo($account, MerchantStatus::Suspended, $reason);

        $this->events->dispatch(new MerchantRoutingSuspended($account->provider, $account->account_reference, $reason));

        return true;
    }

    /** Let a suspended merchant receive again. Refused for a terminated one — see the class docblock. */
    public function reinstate(MerchantAccount $account): bool
    {
        if (! $account->status->isReinstatable()) {
            return false;
        }

        $this->moveTo($account, MerchantStatus::Active, null);

        $this->events->dispatch(new MerchantRoutingReinstated($account->provider, $account->account_reference));

        return true;
    }

    /**
     * End the relationship.
     *
     * Reachable from active AND from suspended, because the two paths are both real: a merchant can
     * disconnect while healthy, or after weeks of being unable to receive. Only a second termination is a
     * no-op — the first one recorded when the platform lost reach, and that date is what a dispute turns on.
     */
    public function terminate(MerchantAccount $account, string $reason): bool
    {
        if ($account->status === MerchantStatus::Terminated) {
            return false;
        }

        $this->moveTo($account, MerchantStatus::Terminated, $reason);

        $this->events->dispatch(new MerchantTerminated($account->provider, $account->account_reference, $reason));

        return true;
    }

    private function moveTo(MerchantAccount $account, MerchantStatus $status, ?string $reason): void
    {
        $account->forceFill([
            'status' => $status,
            'status_reason' => $reason,
            'status_changed_at' => Carbon::now(),
        ])->save();
    }
}
