<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * When the local engine collects a period of a subscription: when it begins, or when it ends.
 *
 * In advance, a period is paid before the customer has it: the first one when the subscription starts, or when its
 * trial ends, and each later one at the moment the period before it ends. A swap or a seat change inside a paid
 * period settles the rest of that period at once. In arrears, a period is paid when it closes, and a swap or a seat
 * change is priced into the bill that closes it.
 *
 * The timing is recorded on each subscription when it starts and never changes afterwards. Switching a running
 * subscription would collect one period twice at the moment of the switch, once as the bill that closes it and once
 * as the payment for the next, so a change of the setting reaches new subscriptions only.
 */
enum CollectionTiming: string
{
    /** Each period is paid when it begins. */
    case InAdvance = 'in_advance';

    /** Each period is paid when it ends. */
    case InArrears = 'in_arrears';

    /** Where an installation states when new subscriptions are collected. */
    public const string CONFIG_KEY = 'billing.collection_timing';

    /** The configured timing, or collection in advance where nothing valid is configured. */
    public static function fromConfigured(mixed $configured): self
    {
        return self::tryFrom(is_string($configured) ? $configured : '') ?? self::InAdvance;
    }
}
