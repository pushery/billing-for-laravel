<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Support\UsageFlusher;
use Pushery\Billing\Support\UsageMeter;

/**
 * Hands recorded usage to the provider that bills it.
 *
 * Deliberately separate from `billing:run`, which advances a local billing cycle: this one talks to a
 * network, and the two fail in different ways and want different cadences. It exits successfully on a
 * provider outage — a growing backlog is not a crash, and a non-zero exit would make a scheduler
 * escalate an outage it cannot fix. Usage that genuinely cannot be reported is escalated by the flusher
 * itself, loudly, because that is revenue that will not be collected.
 */
final class FlushUsageCommand extends Command
{
    protected $signature = 'billing:usage:flush';

    protected $description = 'Report recorded metered usage to the billing provider';

    public function handle(UsageFlusher $flusher, UsageMeter $counters, Repository $config): int
    {
        // Reclaim first, and reclaim even when billing is switched off. A hold is allowance a request took
        // and never gave back — a worker killed mid-request — and while it stands, the owner is refused
        // requests they never spent. That has to be swept whether or not anyone is being billed.
        $reclaimed = $counters->expire();

        if ($reclaimed > 0) {
            $this->components->info("Reclaimed {$reclaimed} expired usage reservation(s).");
        }

        if (! (bool) $config->get('billing.enabled', true)) {
            $this->components->info('Billing is disabled; no usage was reported.');

            return self::SUCCESS;
        }

        $reported = $flusher->flush();

        $this->components->info("Reported {$reported} usage rollup(s).");

        return self::SUCCESS;
    }
}
