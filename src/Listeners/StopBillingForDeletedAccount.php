<?php

declare(strict_types=1);

namespace Pushery\Billing\Listeners;

use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Pushery\Billing\Contracts\SubscriptionActions;
use Pushery\Billing\Enums\SubscriptionState;
use Pushery\Billing\Events\BillableAccountDeleting;
use Pushery\Billing\Exceptions\DeletedAccountStillSubscribed;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\ValueObjects\MerchantScope;
use Throwable;

/**
 * Stops an owner's live billing the instant their account is being deleted: on {@see BillableAccountDeleting}
 * it cancels every subscription IMMEDIATELY (not into a grace period — the account is going away), so a
 * deleted account never keeps paying at the provider.
 *
 * EVERY subscription means every merchant scope, not only the platform's. `cancelNow()` acts on one scope,
 * and a null scope is the platform: a marketplace member who subscribed to two creators kept both of those
 * subscriptions, and kept paying for them, after deleting the account. The scopes come from the owner's own
 * rows, and the platform is always asked as well, as it always was, because a provider can hold a
 * subscription the local mirror has not recorded yet.
 *
 * Runs SYNCHRONOUSLY (never queued): the cancel must complete while the owner still exists and before the
 * row is erased. A transient provider failure is TOLERATED, because leaving a user who asked to leave
 * undeletable is worse than a cancel that has to be retried, and a failure in one scope does not stop the
 * others. It is not SILENT: it is logged, and reported as {@see DeletedAccountStillSubscribed} through the
 * application's exception handler, both with the class name and the scope only, never the provider's message.
 */
final readonly class StopBillingForDeletedAccount
{
    public function __construct(private SubscriptionActions $actions) {}

    public function handle(BillableAccountDeleting $event): void
    {
        foreach ($this->scopesOf($event->owner) as $merchant) {
            try {
                $this->actions->cancelNow($event->owner, $merchant);
            } catch (Throwable $e) {
                Log::warning('Could not stop live billing for a deleting account; the deletion continues.', [
                    'exception' => $e::class,
                    'merchant' => $merchant->uid(),
                ]);

                // The log line reached nobody who could end the subscription by hand, so the application's
                // exception handler hears about it too: the class and the scope, never the provider's message.
                $this->report(DeletedAccountStillSubscribed::whileDeleting($event->owner, $merchant, $e));
            }
        }
    }

    /**
     * Hand the failure to the application's exception handler, where one is bound.
     *
     * Through the contract rather than Foundation's `report()` helper, which this package does not ship against. A
     * container without a handler has nobody to tell, and the log line above has already been written.
     */
    private function report(DeletedAccountStillSubscribed $failure): void
    {
        $container = Container::getInstance();

        if ($container->bound(ExceptionHandler::class)) {
            $container->make(ExceptionHandler::class)->report($failure);
        }
    }

    /**
     * The platform, and every merchant scope in which the owner still has a subscription that is not over.
     *
     * A terminal row is left out: canceling it again would rewrite when a finished subscription ended.
     *
     * @return list<MerchantScope>
     */
    private function scopesOf(Model $owner): array
    {
        $scopes = [MerchantScope::platform()->uid() => MerchantScope::platform()];

        $subscriptions = Subscription::query()
            ->forOwner($owner)
            ->ofDefaultType()
            ->merchantScoped()
            ->whereNotIn('status', [SubscriptionState::Ended->value, SubscriptionState::IncompleteExpired->value])
            ->get(['merchant_uid']);

        foreach ($subscriptions as $subscription) {
            $scopes[$subscription->merchant_uid] = MerchantScope::fromUid($subscription->merchant_uid);
        }

        return array_values($scopes);
    }
}
