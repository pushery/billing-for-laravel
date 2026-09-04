<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;

/**
 * The active driver cannot route money to a merchant, so the marketplace path is not available.
 *
 * Thrown in two places for the same reason. At boot, when the marketplace is switched on over a driver
 * that cannot route: a marketplace that looks enabled but silently behaves as single-seller is the
 * failure this refuses. At call time, when something asks for the marketplace rails anyway: that is a
 * programming error, and answering it with null would push the mistake downstream to whatever
 * dereferences it.
 *
 * Concatenation in this class assembles sentence text rather than behavior, so swapping or dropping
 * a fragment measures where the line was wrapped, not what a test asserts. The values themselves are
 * held by a dedicated guard that varies every parameter individually.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class MarketplaceUnsupported extends RuntimeException
{
    public static function driverCannotRoute(string $driver): self
    {
        return new self(
            "The active billing driver [{$driver}] does not route money to merchants, so the ".
            'marketplace path is unavailable. A driver announces the capability by implementing '.
            'Pushery\\Billing\\Contracts\\RoutesMoney. Either use a driver that routes money, or leave '.
            'billing.marketplace.enabled off (the default) and run as a single seller.'
        );
    }

    /**
     * The driver was handed a separate-transfer routing, which it cannot complete.
     *
     * On this shape the platform takes the whole payment and the merchant's share is moved by a LATER
     * call — one THESE RAILS cannot make, because it can only go out once the payment has succeeded, which
     * is after `charge()` has returned. Accepting the routing here would settle the entire amount on the
     * platform account and never pay the merchant — with a successful `ChargeResult` and a null transfer
     * reference that reads exactly like a transfer still settling.
     *
     * The call itself ships: `StripeMerchantTransfers` implements `MovesMerchantShare` and `RoutedPayment`
     * makes it. This paragraph used to say it did not exist yet, which the rename note below already
     * contradicted in the same file — the factory was renamed off "not implemented" precisely because the
     * gap had closed, and the sentence above it was left saying otherwise.
     *
     * Refused rather than completed, which is what this package tells driver authors to do in the same
     * situation: "a driver that cannot serve a routing must THROW, never no-op." A loud failure costs one
     * checkout; the silent one costs every routed sale until somebody reconciles an account.
     *
     * This refusal is PERMANENT and correct, not a placeholder. The rails alone genuinely cannot serve the
     * lane: the transfer can only be made once the payment has actually succeeded, which is after charge()
     * has already returned. RoutedPayment is where the two halves meet, and it is the supported path.
     */
    /**
     * Named for what the caller should DO, not for a gap that no longer exists.
     *
     * It used to be `separateTransferNotImplemented`, and by the time the transfer was built that name was
     * telling consumers to wait for a later version while the capability sat in the same release. A factory
     * name is the first thing in a stack trace and the string people grep for; "not implemented" sends them
     * away, and the message right below it was already pointing them at RoutedPayment.
     */
    public static function separateTransferNeedsRoutedPayment(): self
    {
        return new self(
            'The payment rails cannot serve a separate-transfer routing on their own: the charge and the '.
            'transfer are two calls, and the second can only be made once the payment has succeeded — '.
            'after this method has returned. Route the sale through Pushery\\Billing\\Marketplace\\'.
            'RoutedPayment, which makes both calls and records them, or use ChargeType::Destination, where '.
            'the provider moves the share as part of the payment (the seller-of-record posture must permit '.
            'it — billing.marketplace.charge_type_by_posture).'
        );
    }

    /**
     * The sale routes separately and the driver has no way to move the share.
     *
     * Thrown BEFORE the buyer is charged, which is the entire point of checking here. Discovering this
     * afterwards would leave a completed payment sitting on the platform account with no way to pay the
     * merchant and no signal that anything was wrong.
     */
    public static function cannotMoveMerchantShare(string $driver): self
    {
        return new self(
            "The [{$driver}] driver cannot move a merchant's share, so a separate-transfer sale would take ".
            'the whole payment and never pay the merchant. Bind an implementation of '.
            'Pushery\\Billing\\Contracts\\MovesMerchantShare, or use ChargeType::Destination, where the '.
            'provider moves the share as part of the payment.'
        );
    }

    public static function billingDisabled(): self
    {
        return new self(
            'Billing is disabled (billing.enabled = false), so the no-op driver is active and no money '.
            'can be routed to a merchant. Enable billing before enabling the marketplace.'
        );
    }
}
