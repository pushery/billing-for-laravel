<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use LogicException;

/**
 * An act asked of the Shopify driver that Shopify's billing does not have, refused with the way it does have.
 *
 * Shopify bills the merchant on the Shopify invoice, keeps one subscription per installation of an app, and
 * changes or restarts a subscription only through a new one the merchant approves in the Shopify admin. A seam
 * that cannot express that refuses rather than doing half of it.
 *
 * Concatenation in this class assembles sentence text rather than behavior, so swapping or dropping a fragment
 * measures where the line was wrapped, not what a test asserts.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class NotPossibleOnShopify extends LogicException
{
    /** `SubscriptionActions::swap()` returns nothing, so it cannot hand back the page where the merchant approves. */
    public static function changeThePlan(): self
    {
        return new self(
            'Shopify changes a plan only through a new subscription the merchant approves. Open it with '.
            '`Checkout::subscribe()` and send the merchant to the `checkout_url` it returns; Shopify replaces the '.
            'current subscription once the new one is approved.'
        );
    }

    public static function resume(): self
    {
        return new self(
            'A canceled Shopify subscription does not come back. Open a new one with `Checkout::subscribe()` and send '.
            'the merchant to the `checkout_url` it returns.'
        );
    }

    public static function useThePaymentRails(string $act): self
    {
        return new self(
            "The Shopify driver cannot {$act}: Shopify bills the merchant on the Shopify invoice, and an app never "
            .'holds a payment method of theirs. Charge through a subscription, a usage record or a one-time purchase.'
        );
    }

    public static function holdASecondContract(string $type): self
    {
        return new self(
            "Shopify keeps one subscription per installation of an app, so there is no contract of the type `{$type}` "
            .'beside it. A new subscription replaces the current one.'
        );
    }

    public static function carryACallerReference(): self
    {
        return new self(
            'A Shopify subscription carries no data of the app, so a caller reference would not come back with the '.
            'webhook. Keep the correlation on your side, keyed by the `subscription_id` the checkout returns.'
        );
    }

    public static function billUsage(string $tier, string $meter): self
    {
        return new self(
            "The tier `{$tier}` prices the usage of `{$meter}` past its allowance, and the Shopify driver reports no "
            .'usage to Shopify, so the units would be counted and never charged. Sell units in advance as an add-on, '
            .'whose purchase grants them, or leave the tier\'s usage unpriced.'
        );
    }

    public static function billEvery(string $interval): self
    {
        return new self(
            "Shopify bills a subscription every 30 days or every year, not every {$interval}. Give the tier the "
            .'interval `month` or `year`.'
        );
    }
}
