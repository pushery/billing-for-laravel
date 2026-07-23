<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use Illuminate\Console\Command;
use Pushery\Billing\Support\BillingManager;
use Pushery\Billing\Support\ScheduledSwapRunner;

/**
 * Advances the recurring billing cycle for the active driver. Stripe drives its own cycle, so the engine
 * tick is a no-op there; the local engine owns the cycle and advances every due subscription
 * when this runs (scheduled hourly). It exists in v1 so those drivers slot in without a rewrite, and it
 * honors the master switch — a disabled install resolves the NullDriver and does nothing.
 *
 * It also applies plan changes scheduled for later — a downgrade deferred to the period end. That runs
 * regardless of driver: a downgrade scheduled under Stripe still has to be pushed to the provider when its
 * date arrives, and the engine tick would not do it.
 */
final class BillingRunCommand extends Command
{
    protected $signature = 'billing:run';

    protected $description = 'Advance every due local billing cycle and apply scheduled plan changes.';

    public function handle(BillingManager $manager, ScheduledSwapRunner $swaps): int
    {
        $manager->driver()->engine()->tick();

        $applied = $swaps->runDue();

        if ($applied > 0) {
            $this->components->info("Applied {$applied} scheduled plan change(s).");
        }

        return self::SUCCESS;
    }
}
