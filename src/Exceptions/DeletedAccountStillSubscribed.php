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
    public function __construct(
        public readonly string $owner,
        public readonly string $merchantScope,
        public readonly string $failure,
    ) {
        parent::__construct(
            "The account [{$owner}] is being deleted, but its subscription in scope [{$merchantScope}] could not be ended: ".
            "the provider call failed with [{$failure}]. The provider may keep charging until it is ended by hand."
        );
    }

    /** One cancellation that did not go through while the account was being deleted. */
    public static function whileDeleting(Model $owner, MerchantScope $merchant, Throwable $failure): self
    {
        $key = $owner->getKey();

        return new self($owner->getMorphClass().':'.(is_int($key) || is_string($key) ? (string) $key : 'unsaved'), $merchant->uid(), $failure::class);
    }
}
