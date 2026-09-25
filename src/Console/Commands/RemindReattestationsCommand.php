<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Pushery\Billing\Marketplace\ReattestationReminderSweep;

/**
 * Remind the creators whose tax attestation is due for renewal, before it runs out.
 *
 * An attestation expires after the year boundary, once the grace period has passed, and the hold that
 * follows is announced by `billing:tax-holds:announce`. This is the notice before it: once when the year
 * turns and the renewal falls due, once more shortly before the attestation runs out.
 */
final class RemindReattestationsCommand extends Command
{
    protected $signature = 'billing:tax-holds:remind';

    protected $description = 'Remind creators whose tax attestation is due for renewal';

    public function handle(ReattestationReminderSweep $sweep): int
    {
        $sent = $sweep->remind(CarbonImmutable::now());

        $this->components->info($sent === 0
            ? 'No tax attestation needed a reminder.'
            : "Reminded {$sent} creator(s) that their tax attestation is due.");

        return self::SUCCESS;
    }
}
