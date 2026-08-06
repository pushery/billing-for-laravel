<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Pushery\Billing\Dunning\PaymentReminderSweep;

/**
 * Send the daily reminder to every subscription still inside its cure window.
 *
 * Like the tax-hold announcement next door, this exists because nothing else notices. A payment failing
 * writes a row and can be watched; a day passing inside the window writes nothing at all. Without a
 * scheduled sweep the customer hears once, when the payment failed, and then again only when the
 * subscription is gone.
 *
 * Safe to run more than once a day — the sweep marks the day it sent, so a second run in the same day sends
 * nothing. That is what makes it safe to schedule and safe to re-run by hand after a partial failure.
 */
final class RemindDelinquentSubscriptionsCommand extends Command
{
    protected $signature = 'billing:dunning:remind';

    protected $description = 'Remind subscriptions in arrears that are still inside the cure window';

    public function handle(PaymentReminderSweep $sweep): int
    {
        $sent = $sweep->remind(CarbonImmutable::now());

        // "Ran and found nothing" has to be distinguishable from "never ran". A silent success reads as an
        // absent scheduler, and an absent scheduler is exactly the failure this command exists to prevent.
        $this->components->info($sent === 0
            ? 'No subscription is inside its cure window today.'
            : "Reminded {$sent} subscription(s) in arrears.");

        return self::SUCCESS;
    }
}
