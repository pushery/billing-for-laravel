<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Support\BillingEraser;
use Pushery\Billing\Support\OwnerScopedTables;

/**
 * Erases one owner's billing data — the operational half of a right-to-erasure request (GDPR Art. 17).
 *
 * This command is the COMPLETE path, and the documented one. A model observer would look more convenient,
 * but a mass delete (`User::query()->where(...)->delete()`) fires no model events at all, so an app relying
 * on one would under-erase silently and never know.
 *
 * It does NOT delete the invoices. The law requires them to be kept, and to carry the buyer's name and
 * address while they are — see BillingEraser.
 */
final class EraseOwnerCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'billing:erase
        {owner : The owner\'s primary key}
        {--dry-run : Show what would be erased and what would be kept, change nothing}
        {--force : Skip the production confirmation (for an automated erasure pipeline)}';

    protected $description = "Erase an owner's billing data, retaining the records the law requires";

    public function handle(Repository $config, BillingEraser $eraser): int
    {
        $model = $config->get('billing.customer.model');

        if (! is_string($model) || ! is_a($model, Model::class, true)) {
            $this->components->error('billing.customer.model is not configured; there is no owner to erase.');

            return self::FAILURE;
        }

        $owner = $model::query()->find($this->argument('owner'));

        if (! $owner instanceof Model) {
            $this->components->error('No such owner.');

            return self::FAILURE;
        }

        if ($this->option('dry-run') === true) {
            $this->components->info('Dry run: nothing was erased.');
            $this->line('Would purge: '.implode(', ', OwnerScopedTables::PURGED));
            $this->line('Would scrub the stored webhook payloads.');
            $this->line('Would KEEP, unlinked (the law requires it): '.implode(', ', OwnerScopedTables::RETAINED));

            return self::SUCCESS;
        }

        // Erasure is irreversible. In production, confirm before acting so a mistyped owner key cannot purge
        // the wrong person — unless --force is passed, which an automated Art. 17 pipeline uses on purpose.
        if (! $this->confirmToProceed('This permanently erases the owner\'s billing data.')) {
            return self::FAILURE;
        }

        $report = $eraser->erase($owner);

        foreach ($report->purged as $table => $rows) {
            $this->line("purged {$rows} row(s) from {$table}");
        }

        foreach ($report->retained as $table => $rows) {
            $this->line("kept {$rows} row(s) in {$table}, unlinked from the owner (retention)");
        }

        foreach ($report->unspentCredit as $currency => $minor) {
            // Money the customer was still owed, now gone. Nobody should learn this from a diff.
            $this->components->warn("The owner still had {$minor} ({$currency}) of unspent credit; it was recorded to the audit ledger and purged.");
        }

        $this->components->info($report->isEmpty() ? 'That owner had no billing data.' : 'Erased.');

        return self::SUCCESS;
    }
}
