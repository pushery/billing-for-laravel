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
 * the owner's live subscriptions (see {@see StopBillingForDeletedAccount}) — the platform's own and one in
 * every merchant scope the owner still subscribes in, so a marketplace member's subscriptions to creators
 * end with the account too, and a deleted account never lingers as an active, still-charging subscription
 * at the provider.
 *
 * Present-continuous by design ("…Deleting", not "…Deleted"): the listener runs WHILE the owner still
 * exists, so `cancelNow` can resolve the owner's provider reference before the row is erased. The package's
 * own {@see BillingEraser} dispatches this before it erases; an app with a custom
 * delete UI dispatches it itself, right after re-confirming identity and before `$user->delete()`.
 *
 * ## A queued listener receives the owner's key, and nothing else of it
 *
 * A listener that implements `ShouldQueue` runs after the account is gone, so it cannot read the owner back
 * the way the other events let it. And the owner's attributes, hidden ones included, must not wait in the
 * queue or in `failed_jobs`, which the deletion this event announces does not reach. So the event serializes
 * the owner as its class, key and connection, and a queued listener receives an instance of that class that
 * carries only the key: enough to clean up what the application keeps under it. A listener that runs
 * synchronously receives the owner as it was dispatched.
 */
final class BillableAccountDeleting
{
    public function __construct(public Model $owner) {}

    /**
     * @return array{owner: array{class: class-string<Model>, key: mixed, connection: string|null}}
     */
    public function __serialize(): array
    {
        return ['owner' => [
            'class' => $this->owner::class,
            'key' => $this->owner->getKey(),
            'connection' => $this->owner->getConnectionName(),
        ]];
    }

    /**
     * @param  array{owner: array{class: class-string<Model>, key: mixed, connection: string|null}}  $data
     */
    public function __unserialize(array $data): void
    {
        $class = $data['owner']['class'];
        $shell = new $class;

        $this->owner = $shell->newFromBuilder([$shell->getKeyName() => $data['owner']['key']], $data['owner']['connection']);
    }
}
