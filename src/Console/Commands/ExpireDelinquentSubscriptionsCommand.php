<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Pushery\Billing\Dunning\SubscriptionExpirySweep;

/**
 * Expire every subscription whose cure window has run out, and send the one final message.
 *
 * Scheduled beside `billing:dunning:remind`, and the pair is meant to run in that order on any given day —
 * not because either would misbehave otherwise (they select disjoint sets), but because a customer whose
 * window ends today should receive the final notice rather than a countdown that ends at zero.
 *
 * Safe to run more than once: an expired subscription carries a termination marker and is not selected again.
 */
final class ExpireDelinquentSubscriptionsCommand extends Command
{
    protected $signature = 'billing:dunning:expire';

    protected $description = 'Expire subscriptions whose cure window has run out, canceling only those';

    public function handle(SubscriptionExpirySweep $sweep): int
    {
        $expired = $sweep->expire(CarbonImmutable::now());

        // "Ran and found nothing" must be distinguishable from "never ran" — a silent success reads as an
        // absent scheduler, and this command cancels things, so an absent one is not noticed until a customer
        // asks why they are still being charged for what they stopped paying.
        $this->components->info($expired === 0
            ? 'No subscription has run out its cure window today.'
            : "Expired {$expired} subscription(s) whose cure window ran out.");

        return self::SUCCESS;
    }
}
