<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Pushery\Billing\Marketplace\SellerDataEscalationSweep;
use Pushery\Billing\Marketplace\WithheldMerchantShares;

/**
 * Move each seller whose record is incomplete one step along the escalation, then pay out what may move.
 *
 * The escalation first, the release after it, and in that order on purpose: a seller who completed their
 * record yesterday has their measure ended by the first half and everything held back paid out by the
 * second, on the same run, rather than a day later.
 *
 * It says so when it cannot run at all: without a record source nothing about any record is known, and a
 * run that printed "nothing to do" there would read like a clean bill.
 */
final class EscalateSellerDataCommand extends Command
{
    protected $signature = 'billing:seller-data:escalate';

    protected $description = 'Remind sellers whose record is incomplete, apply or end measures, and release withheld shares';

    public function handle(SellerDataEscalationSweep $sweep, WithheldMerchantShares $withheld): int
    {
        if (! $sweep->applies()) {
            $this->components->warn('No seller was assessed: the reporting profile asks for no escalation, or no seller record source is bound.');
        } else {
            $outcome = $sweep->run(CarbonImmutable::now());

            $this->components->info(sprintf(
                'Assessed %d seller(s): %d reminded, %d measure change(s), %d record(s) completed.',
                $outcome['assessed'],
                $outcome['reminded'],
                $outcome['measures'],
                $outcome['resolved'],
            ));
        }

        $released = $withheld->release();

        $this->components->info(sprintf(
            'Withheld shares: %d released, %d still held, %d failed to move.',
            $released['released'],
            $released['held'],
            $released['failed'],
        ));

        return self::SUCCESS;
    }
}
