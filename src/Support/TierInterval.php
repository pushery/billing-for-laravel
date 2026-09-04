<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Enums\BillingInterval;

/**
 * How long a tier's billing period runs.
 *
 * ## Why this is one class rather than a line in each caller
 *
 * Two places decide a period: the effect that creates a subscription writes its FIRST one, and the engine
 * advances every one after it. Both had the interval hardcoded to a month, and both were wrong the same way
 * — an annual subscriber was charged the annual price again thirty days later, twelve times a year, off
 * their stored mandate.
 *
 * Fixed separately they would be two readings of one fact, and the failure of that shape is not that they
 * disagree loudly: it is that the first cycle and every cycle after it would describe different
 * subscriptions, which reads on an invoice as a period somebody mistyped.
 *
 * ## Monthly on anything unreadable
 *
 * A tier with no interval, an interval nobody recognizes, or no tier at all. That is what every existing
 * install already gets, so reading the setting changes nothing for anybody who never set it.
 */
final readonly class TierInterval
{
    public function __construct(private Repository $config) {}

    public function for(?string $tierKey): BillingInterval
    {
        $configured = $this->config->get("billing.tiers.{$tierKey}.interval");

        return (is_string($configured) ? BillingInterval::tryFrom($configured) : null) ?? BillingInterval::Month;
    }
}
