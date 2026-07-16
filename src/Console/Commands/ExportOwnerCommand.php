<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\Filesystem;
use Pushery\Billing\Support\BillingDataExport;

/**
 * Everything the package holds about one owner, as JSON — the answer to a subject-access or
 * data-portability request (GDPR Art. 15, Art. 20).
 */
final class ExportOwnerCommand extends Command
{
    protected $signature = 'billing:export
        {owner : The owner\'s primary key}
        {--path= : Write the JSON here instead of to the terminal}';

    protected $description = 'Export everything the package holds about one owner';

    public function handle(Repository $config, BillingDataExport $export, Filesystem $files): int
    {
        $model = $config->get('billing.customer.model');

        if (! is_string($model) || ! is_a($model, Model::class, true)) {
            $this->components->error('billing.customer.model is not configured; there is no owner to export.');

            return self::FAILURE;
        }

        $owner = $model::query()->find($this->argument('owner'));

        if (! $owner instanceof Model) {
            $this->components->error('No such owner.');

            return self::FAILURE;
        }

        $json = json_encode($export->for($owner), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $path = $this->option('path');

        if (is_string($path) && $path !== '') {
            $files->put($path, $json);
            $this->components->info("Wrote the export to {$path}.");

            return self::SUCCESS;
        }

        $this->line($json);

        return self::SUCCESS;
    }
}
