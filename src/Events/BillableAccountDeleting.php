<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Listeners\StopBillingForDeletedAccount;
use Pushery\Billing\Support\BillingEraser;

/**
 * Fired the moment a billable account is about to be deleted — the hook that lets an app STOP live billing
 * before the owner is gone. An app deletes accounts from its own flow (a "delete my account" button, an
 * admin action, a GDPR erase); whichever it is, dispatching this event first guarantees the package cancels
 * the owner's live subscription (see {@see StopBillingForDeletedAccount}) — so a
 * deleted account never lingers as an active, still-charging subscription at the provider.
 *
 * Present-continuous by design ("…Deleting", not "…Deleted"): the listener runs WHILE the owner still
 * exists, so `cancelNow` can resolve the owner's provider reference before the row is erased. The package's
 * own {@see BillingEraser} dispatches this before it erases; an app with a custom
 * delete UI dispatches it itself, right after re-confirming identity and before `$user->delete()`.
 */
final class BillableAccountDeleting
{
    public function __construct(public Model $owner) {}
}
