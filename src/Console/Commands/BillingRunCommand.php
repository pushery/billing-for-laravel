<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use Illuminate\Console\Command;
use Pushery\Billing\Support\BillingManager;

/**
 * Advances the recurring billing cycle for the active driver. Stripe drives its own cycle, so this is
 * a no-op there; the Mollie/Adyen local engine owns the cycle and advances every due subscription when
 * this runs (scheduled hourly). It exists in v1 so those drivers slot in without a rewrite, and it
 * honours the master switch — a disabled install resolves the NullDriver and does nothing.
 */
final class BillingRunCommand extends Command
{
    protected $signature = 'billing:run';

    protected $description = 'Advance every due local billing cycle (a no-op under Stripe).';

    public function handle(BillingManager $manager): int
    {
        $manager->driver()->engine()->tick();

        return self::SUCCESS;
    }
}
