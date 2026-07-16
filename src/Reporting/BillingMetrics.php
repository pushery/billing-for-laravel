<?php

declare(strict_types=1);

namespace Pushery\Billing\Reporting;

use Pushery\Billing\ValueObjects\Money;

/**
 * A point-in-time read-model of the billing account's health — the recurring revenue and the
 * subscription counts a dashboard shows. It is a plain snapshot: the app decides how to display or
 * cache it; the package neither stores nor schedules it. {@see BillingMetricsReporter} computes it from
 * the local subscription rows, so reading it costs no provider round-trip.
 */
final readonly class BillingMetrics
{
    public function __construct(
        /** Monthly-normalized recurring revenue at declared list prices — see the reporter for the exact semantics. */
        public Money $mrr,
        /** Subscriptions currently active (paying). */
        public int $activeSubscriptions,
        /** Subscriptions currently on trial (a future trial end, or the provider's trialing status). */
        public int $trials,
        /** Subscriptions walking the dunning ladder (past due, or a raised local dunning level). */
        public int $inDunning,
        /** Subscriptions that ended within the trailing window — the churn count for {@see $windowDays}. */
        public int $canceledInWindow,
        /** The trailing window, in days, the churn count is measured over. */
        public int $windowDays,
    ) {}
}
