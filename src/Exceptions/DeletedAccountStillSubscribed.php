<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\ValueObjects\MerchantScope;
use RuntimeException;
use Throwable;

/**
 * An account is being deleted and one of its subscriptions could not be ended, so the provider may keep charging.
 *
 * Reported, never thrown. `StopBillingForDeletedAccount` lets the deletion go on when a scope's cancellation fails,
 * because an account that cannot be deleted is worse than a cancellation that has to be repeated. The log line it
 * wrote reached nobody who could repeat it, so this goes through the application's exception handler as well, where
 * an error tracker sees it without anybody listening for anything.
 *
 * It names the owner, the scope and the class of the failure, and nothing the provider said: the provider's message
 * can carry personal data of the very account that asked to have its data erased.
 *
 * Concatenation in this class assembles sentence text rather than behavior, so swapping or dropping a fragment
 * measures where the line was wrapped, not what a test asserts. The values themselves are held by a dedicated
 * guard that varies every parameter individually.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class DeletedAccountStillSubscribed extends RuntimeException
{
    /**
     * @param  string  $owner  the account being deleted
     * @param  string  $merchantScope  the scope the subscription runs in
     * @param  string  $failure  the class of what the provider call threw
     * @param  ?string  $subscriber  who holds the subscription, when it is not the account being deleted: a fan of a
     *                               merchant whose account is going away
     */
    public function __construct(
        public readonly string $owner,
        public readonly string $merchantScope,
        public readonly string $failure,
        public readonly ?string $subscriber = null,
    ) {
        parent::__construct($subscriber === null
            ? "The account [{$owner}] is being deleted, but its subscription in scope [{$merchantScope}] could not be ended: ".
              "the provider call failed with [{$failure}]. The provider may keep charging until it is ended by hand."
            : "The account [{$owner}] is being deleted, but a subscription [{$subscriber}] holds with it in scope [{$merchantScope}] ".
              "could not be ended: the provider call failed with [{$failure}]. The provider may keep charging [{$subscriber}] until it is ended by hand."
        );
    }

    /** One cancellation that did not go through while the account was being deleted. */
    public static function whileDeleting(Model $owner, MerchantScope $merchant, Throwable $failure): self
    {
        return new self(self::identify($owner), $merchant->uid(), $failure::class);
    }

    /** One subscription somebody else holds with a merchant whose account is being deleted, which did not end. */
    public static function heldWithDeletingMerchant(Model $merchant, Model $subscriber, MerchantScope $scope, Throwable $failure): self
    {
        return new self(self::identify($merchant), $scope->uid(), $failure::class, self::identify($subscriber));
    }

    /** The morph class and the key, which names the account without anything that could identify the person. */
    private static function identify(Model $model): string
    {
        $key = $model->getKey();

        return $model->getMorphClass().':'.(is_int($key) || is_string($key) ? (string) $key : 'unsaved');
    }
}
