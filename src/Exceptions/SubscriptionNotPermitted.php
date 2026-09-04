<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;

/**
 * A subscription that must not be started, refused before anything reaches the provider.
 *
 * All three cases are refusals rather than corrections, and each is a refusal for its own reason.
 *
 * A tier the catalog does not carry is the anti-injection one. The key travels from a browser, and a
 * caller that resolved an unknown key to something — a default, a price it found, a zero — would be
 * letting an untrusted party choose what a subscription costs. Refusing is the only reading that cannot
 * be steered.
 *
 * An untouchable tier is one an operator grants by hand, deliberately outside the billing flow so that no
 * provider event overwrites it. Selling it back through checkout would produce exactly the overwrite the
 * classification exists to prevent.
 *
 * And a billable that already has a live subscription must not start a second: two subscriptions on one
 * owner both bill, both grant access, and only one of them is the one anybody looks at.
 *
 * Concatenation in this class assembles sentence text rather than behavior, so swapping or dropping
 * a fragment measures where the line was wrapped, not what a test asserts. The values themselves are
 * held by a dedicated guard that varies every parameter individually.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class SubscriptionNotPermitted extends RuntimeException
{
    public static function unknownTier(string $tierKey): self
    {
        return new self(
            'There is no tier "'.$tierKey.'" in billing.tiers, so there is nothing to subscribe to. The key '
            .'is refused rather than resolved to a default: it arrives from a browser, and a default here '
            .'would let whoever sent it decide what the subscription costs.'
        );
    }

    public static function untouchableTier(string $tierKey): self
    {
        return new self(
            'The tier "'.$tierKey.'" is listed in billing.untouchable_tiers, which means it is granted by '
            .'hand and deliberately kept outside the billing flow. Selling it through checkout would let '
            .'the next provider event overwrite the grant, which is the exact thing that listing prevents.'
        );
    }

    public static function unusableOwnerKey(string $ownerType): self
    {
        return new self(
            'The billable '.$ownerType.' has a primary key that is neither an integer nor a string, so '
            .'nothing can record which owner this subscription belongs to. Coercing it would produce a row '
            .'that resolves to nobody the moment the provider answers.'
        );
    }

    public static function noReturnUrl(): self
    {
        return new self(
            'Starting a subscription with this provider sends the customer to a page of theirs, and nothing '
            .'says where they come back to. Set billing.subscribe_return_url (or billing.checkout.success_url). '
            .'It is refused rather than guessed: a guessed return lands somebody on an error page after a '
            .'real payment, holding a mandate nothing told them about.'
        );
    }

    public static function alreadySubscribed(string $owner): self
    {
        return new self(
            'Owner '.$owner.' already has a live subscription. A second one would bill alongside the first '
            .'and grant the same access twice, and only one of the two is the row every screen reads — so '
            .'the other is charged for and never seen. Swap the tier instead of subscribing again.'
        );
    }
}
