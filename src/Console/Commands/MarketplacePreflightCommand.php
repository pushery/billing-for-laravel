<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Enums\CheckpointStatus;
use Pushery\Billing\Enums\GoLiveStep;
use Pushery\Billing\Preflight\MarketplacePreflight;

/**
 * Prints the marketplace go-live checklist: every point, in order, with what it found.
 *
 * The command is registered whether or not the marketplace is switched on, and that is deliberate. Flipping
 * the switch is the LAST step of the checklist, so a preflight only available after the switch is on is one
 * that can never be run at the moment it is for — and worse, the boot guard would by then be refusing to
 * start the application that would have run it. Registering an artisan command changes no behavior and
 * costs a single-seller install nothing: no config is read until somebody types the command.
 */
final class MarketplacePreflightCommand extends Command
{
    protected $signature = 'billing:marketplace:preflight';

    protected $description = 'Check the marketplace go-live checklist and report what is still open';

    public function handle(Repository $config, MarketplacePreflight $preflight): int
    {
        $enabled = (bool) $config->get('billing.marketplace.enabled', false);

        $this->components->info($enabled
            ? 'The marketplace switch is ON. These points are enforced at boot.'
            : 'The marketplace switch is off. This is what has to hold before it may be switched on.');

        $report = $preflight->run();

        foreach (GoLiveStep::ordered() as $step) {
            $this->newLine();
            $this->line("<options=bold>{$step->order()}. {$step->label()}</>");

            $lines = $report->linesFor($step);

            if ($lines === []) {
                $this->line('   <fg=gray>no checks are registered for this stage</>');

                continue;
            }

            foreach ($lines as $line) {
                $suffix = $line->blocking ? '' : ' <fg=gray>(does not block)</>';

                $this->line("   {$this->badge($line->status)} {$line->key}{$suffix}");
                $this->line("      <fg=gray>{$line->reason}</>");
            }
        }

        $this->newLine();

        $open = $report->openBlockers();

        if ($open === []) {
            $this->components->info('The checklist is clear; billing.marketplace.enabled may be switched on.');

            return self::SUCCESS;
        }

        $this->components->error(count($open).' blocking point(s) are open; the marketplace switch must stay off.');

        return self::FAILURE;
    }

    /** A fixed-width, colored status marker so the report scans down the left edge. */
    private function badge(CheckpointStatus $status): string
    {
        return match ($status) {
            CheckpointStatus::Passed => '<fg=green>PASS       </>',
            CheckpointStatus::Failed => '<fg=red>FAIL       </>',
            CheckpointStatus::Warned => '<fg=yellow>WARN       </>',
            CheckpointStatus::Unreachable => '<fg=red>UNREACHABLE</>',
        };
    }
}
