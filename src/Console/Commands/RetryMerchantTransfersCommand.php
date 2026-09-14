<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use Illuminate\Console\Command;
use Pushery\Billing\Marketplace\UnmovedMerchantShares;

/**
 * Moves the merchant shares that failed to move after their sale was paid.
 *
 * Run by an operator once the cause is fixed: a restricted account released, an outage over. It is not
 * scheduled. Moving money on a timer is a decision an installation takes for itself rather than one the
 * package takes for it, and an hourly retry against an account nobody fixed only repeats the failure.
 *
 * Each share is tried once per run under the idempotency key its sale used, so a transfer that did go through
 * with its answer lost is found rather than paid twice. It exits non-zero while any share is still unmoved.
 */
final class RetryMerchantTransfersCommand extends Command
{
    protected $signature = 'billing:marketplace:retry-transfers {--dry-run}';

    protected $description = 'Move the merchant shares that failed to move after their sale was paid.';

    public function handle(UnmovedMerchantShares $shares): int
    {
        if ($this->option('dry-run') === true) {
            $this->components->info($shares->count().' share(s) would be tried.');

            return self::SUCCESS;
        }

        $outcome = $shares->retry();

        $this->components->info("{$outcome['moved']} share(s) moved, {$outcome['failed']} failed again, {$outcome['skipped']} skipped.");

        return $outcome['failed'] === 0 && $outcome['skipped'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
