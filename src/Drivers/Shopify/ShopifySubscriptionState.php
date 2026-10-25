<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Shopify;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\Exceptions\InvalidFormatException;
use Pushery\Billing\Enums\SubscriptionState;
use Pushery\Billing\Events\SubscriptionStateChanged;

/**
 * A Shopify subscription as the package's neutral event, read from the `AppSubscription` the shop reports.
 *
 * The statuses of a subscription that ran map onto the package's states as their meaning does:
 *
 * - `ACTIVE` is `trialing` while the trial runs, then `active`.
 * - `FROZEN` is held for non-payment and comes back once the merchant pays: `past_due`.
 * - `CANCELLED` is `ended`, or `grace` while the end a cancellation of the app set is still ahead, so the merchant
 *   keeps the period they paid for.
 *
 * A subscription that never ran is no state of the merchant's: it waits for their approval (`PENDING`, and the
 * deprecated `ACCEPTED`), or it was declined or expired before they approved it (`DECLINED`, `EXPIRED`).
 *
 * Shopify counts the trial from the moment the subscription was created, so that is where the trial's end is
 * counted from as well.
 */
final readonly class ShopifySubscriptionState
{
    /** The statuses of a subscription that never ran: waiting for the merchant, declined, or expired unapproved. */
    public const array NEVER_RAN = ['PENDING', 'ACCEPTED', 'DECLINED', 'EXPIRED'];

    /**
     * @param  string  $id  the subscription's id, which the caller has matched to the node
     * @param  array<array-key, mixed>  $node  the `AppSubscription`, with `status`, `createdAt`, `currentPeriodEnd` and `trialDays`
     */
    public static function event(string $shop, string $id, array $node, ShopifySubscriptionRecord $record, CarbonImmutable $now): ?SubscriptionStateChanged
    {
        $trialEnd = self::trialEnd($node);
        $periodEnd = self::moment($node['currentPeriodEnd'] ?? null);

        $state = match (self::status($node)) {
            'ACTIVE' => $trialEnd !== null && $trialEnd > $now->getTimestamp() ? SubscriptionState::Trialing : SubscriptionState::Active,
            'FROZEN' => SubscriptionState::PastDue,
            'CANCELLED' => SubscriptionState::Ended,
            default => null,
        };

        if ($state === null) {
            return null;
        }

        if ($state === SubscriptionState::Ended && $record->endsAt instanceof CarbonInterface && $record->endsAt->getTimestamp() > $now->getTimestamp()) {
            $state = SubscriptionState::Grace;
            $periodEnd = $record->endsAt->getTimestamp();
        }

        return new SubscriptionStateChanged(
            customerReference: $shop,
            state: $state,
            subscriptionReference: $id,
            tierKey: $record->tierKey,
            occurredAt: $now->getTimestamp(),
            periodEnd: $periodEnd,
            trialEnd: $trialEnd,
            declarationReference: $record->declarationReference,
        );
    }

    /** @param array<array-key, mixed> $node */
    public static function status(array $node): string
    {
        $status = $node['status'] ?? null;

        return is_string($status) ? strtoupper($status) : '';
    }

    /** @param array<array-key, mixed> $node */
    private static function trialEnd(array $node): ?int
    {
        $days = $node['trialDays'] ?? null;
        $created = self::moment($node['createdAt'] ?? null);

        if (! is_int($days) || $days <= 0 || $created === null) {
            return null;
        }

        return CarbonImmutable::createFromTimestamp($created)->addDays($days)->getTimestamp();
    }

    private static function moment(mixed $value): ?int
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->getTimestamp();
        } catch (InvalidFormatException) {
            return null;
        }
    }
}
