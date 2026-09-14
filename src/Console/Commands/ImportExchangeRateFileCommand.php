<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Enums\ExchangeRateBasis;
use Pushery\Billing\Exceptions\ExchangeRateFeedUnreadable;
use Pushery\Billing\Tax\ExchangeRateImport;
use Pushery\Billing\Tax\MonthlyRateFile;
use Pushery\Billing\Tax\PublishedRate;

/**
 * Import announced monthly average rates from a file into the local store.
 *
 * ## Why this exists beside the daily importer
 *
 * They answer different rules. The daily importer fetches a central bank's reference rate, which answers the
 * rate at a tax point and the rate at a period end. A domestic German document takes neither: it takes the
 * ministry's announced monthly average, and that table is published behind a page that refuses automated
 * retrieval. Working around that protection is not something a published package should do, so the operator
 * downloads the file — once a month, in a browser, which passes the challenge without trying — and points
 * this command at it.
 *
 * ## The publisher is required, and it is not a formality
 *
 * `--source` is frozen onto every document a rate reaches, and it is what an auditor checks a figure against.
 * A default here would put a publisher's name on figures they never published, which is the one mistake this
 * whole seam is built to prevent.
 */
final class ImportExchangeRateFileCommand extends Command
{
    protected $signature = 'billing:exchange-rates:import-file
        {path : the delimited file to read}
        {--source= : who announced these figures, as it should read on a document}';

    protected $description = 'Import announced monthly average exchange rates from a file into the local store';

    public function handle(Repository $config, MonthlyRateFile $file, ExchangeRateImport $import): int
    {
        if ($config->get('billing.tax_exchange_rates.enabled') !== true) {
            $this->components->warn(
                'billing.tax_exchange_rates.enabled is off, so nothing was imported. Turning it on is what '
                .'says this installation holds rates locally; until then the package refuses every '
                .'conversion rather than answering one.'
            );

            return self::SUCCESS;
        }

        $source = $this->option('source');
        $source = is_string($source) ? trim($source) : '';

        if ($source === '') {
            $this->components->error(
                'Name the publisher with --source. It is frozen onto every document these rates reach and is '
                .'what an auditor checks a figure against, so there is no honest default for it.'
            );

            return self::FAILURE;
        }

        $path = (string) $this->argument('path');
        $contents = is_readable($path) ? file_get_contents($path) : false;

        if ($contents === false) {
            $this->components->error("The file [{$path}] could not be read.");

            return self::FAILURE;
        }

        try {
            $rates = $file->parse($contents);
        } catch (ExchangeRateFeedUnreadable $unreadable) {
            $this->components->error($unreadable->getMessage());

            return self::FAILURE;
        }

        if ($rates === []) {
            $this->components->error(
                'The file has a header and no rows, so nothing was imported. An empty month list is not an '
                .'announcement of nothing — it is a file that was exported before the figures were in it.'
            );

            return self::FAILURE;
        }

        $written = $import->store($rates, [ExchangeRateBasis::CentralBankMonthlyAverage], $source);

        $months = array_map(static fn (PublishedRate $rate): string => $rate->on->format('Y-m'), $rates);
        sort($months);

        $pairs = array_values(array_unique(array_map(
            static fn (PublishedRate $rate): string => $rate->from.'/'.$rate->to,
            $rates,
        )));

        $this->components->info(sprintf(
            'Imported %d announced monthly rate(s) for %s, %s to %s. Source: %s.',
            $written,
            implode(', ', $pairs),
            $months[0],
            $months[count($months) - 1],
            $source,
        ));

        return self::SUCCESS;
    }
}
