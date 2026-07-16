<?php

declare(strict_types=1);

namespace Pushery\Billing\Listeners;

use Illuminate\Support\Facades\Log;
use Pushery\Billing\Contracts\SubscriptionActions;
use Pushery\Billing\Events\BillableAccountDeleting;
use Throwable;

/**
 * Stops an owner's live billing the instant their account is being deleted: on {@see BillableAccountDeleting}
 * it cancels the subscription IMMEDIATELY (not into a grace period — the account is going away), so a
 * deleted account never keeps paying at the provider.
 *
 * Runs SYNCHRONOUSLY (never queued): the cancel must complete while the owner still exists and before the
 * row is erased. A transient provider failure is TOLERATED — it is logged (class name only, no provider
 * PII) and the deletion continues, because leaving a user who asked to leave undeletable is worse than a
 * cancel that has to be retried. The failure is therefore visible in the log and can be re-driven by
 * re-running cancelNow for the owner.
 */
final readonly class StopBillingForDeletedAccount
{
    public function __construct(private SubscriptionActions $actions) {}

    public function handle(BillableAccountDeleting $event): void
    {
        try {
            $this->actions->cancelNow($event->owner);
        } catch (Throwable $e) {
            Log::warning('Could not stop live billing for a deleting account; the deletion continues.', [
                'exception' => $e::class,
            ]);
        }
    }
}
