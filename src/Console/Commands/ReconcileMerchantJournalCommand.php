<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Pushery\Billing\Contracts\ReportsMovedShares;
use Pushery\Billing\Marketplace\ProviderJournalReconciler;
use Pushery\Billing\Support\BillingManager;

/**
 * The operator surface for the provider reconciliation.
 *
 * Read-only by construction. It reports and exits non-zero; it repairs nothing, because the repair depends
 * on which of several stories the difference tells and a sweep cannot tell them apart. A command that
 * "fixed" a drift would be writing over the only evidence that something went wrong.
 */
final class ReconcileMerchantJournalCommand extends Command
{
    protected $signature = 'billing:merchants:reconcile {--driver= : the driver whose rows to audit, defaulting to the active one} {--limit=500 : how many journal rows one sweep reads}';

    protected $description = 'Compare the merchant journal against what the payment provider says it moved';

    public function handle(BillingManager $manager, Container $container, Dispatcher $events): int
    {
        $active = $manager->driver()->name();
        $option = $this->option('driver');
        $driver = is_string($option) && $option !== '' ? $option : $active;

        // ONE INSTALL READS BACK THROUGH ONE DRIVER, so a named driver that is not the active one cannot
        // be audited from here — and it must not be attempted. The option selects which ROWS to sweep; the
        // reader is bound process-wide to whichever driver the install runs. Asking the active provider
        // about another provider's reference gets a refusal, which this command reports as
        // MISSING_AT_PROVIDER: the most serious finding it has, manufactured, once per row.
        //
        // Refused rather than silently swept, because the alternative is a report an operator would act on.
        if ($driver !== $active) {
            $this->components->error(
                "This install runs the {$active} driver, so it can only read {$active} transfers back. "
                ."Sweeping {$driver} rows through it would ask {$active} about references it never issued "
                .'and report every one as missing at the provider. Run this where '.$driver.' is active.'
            );

            return self::FAILURE;
        }

        // Resolved rather than injected, because a driver that makes no transfers binds nothing here and
        // type-hinting the contract would make the command unconstructable instead of merely inapplicable.
        $reader = $container->bound(ReportsMovedShares::class)
            ? $container->make(ReportsMovedShares::class)
            : null;

        if (! $reader instanceof ReportsMovedShares) {
            // Not a failure. A provider that routes the share as part of the payment makes no transfer and
            // has nothing to read back — saying so plainly beats a green run that audited nothing.
            $this->components->warn("The {$driver} driver cannot read transfers back, so there is nothing to reconcile.");

            return self::SUCCESS;
        }

        $limit = (int) $this->option('limit');

        $findings = new ProviderJournalReconciler($reader, $events)
            ->sweep($driver, $limit > 0 ? $limit : 500);

        if ($findings === []) {
            $this->components->info("No drift across the first {$limit} {$driver} journal rows.");

            return self::SUCCESS;
        }

        foreach ($findings as $finding) {
            $delta = $finding->deltaMinor();

            $this->components->twoColumnDetail(
                $finding->transferReference.' ('.$finding->reason.')',
                $delta === null
                    ? 'ours '.$finding->ours->toDecimal().' '.$finding->ours->currency
                    : 'delta '.($delta > 0 ? '+' : '').$delta
            );
        }

        $this->components->error(count($findings).' journal row(s) disagree with the provider. The provider is'
            .' authoritative for what MOVED — correct the local rows, never transfer again to match them.');

        return self::FAILURE;
    }
}
