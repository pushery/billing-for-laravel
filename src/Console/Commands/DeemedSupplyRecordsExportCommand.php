<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Pushery\Billing\Support\RecordsFile;
use Pushery\Billing\Tax\DeemedSupplyRecords;

/**
 * Writes the records of the supplies of goods the platform is treated as making itself, for the days asked for.
 *
 * A supplier in the Union scheme or the import scheme makes its records available electronically when a tax
 * authority asks for them (Art. 369k and 369x VAT Directive). This is that export: one row per document of such a
 * supply issued in the range, the sale and each correction of it, with the content Art. 63c of Implementing
 * Regulation 282/2011 lists, as {@see DeemedSupplyRecords} describes the columns.
 *
 * The file is semicolon-separated, like the returns this package writes, and a field that holds a semicolon, a quote
 * or a line break is quoted, since the description of the goods is free text.
 */
final class DeemedSupplyRecordsExportCommand extends Command
{
    protected $signature = 'billing:deemed-supply-records:export
        {--from= : The first day, as YYYY-MM-DD}
        {--to= : The last day, as YYYY-MM-DD}
        {--path= : Write the file here instead of to the terminal}';

    protected $description = 'Export the records of the supplies of goods the platform is treated as making itself';

    public function handle(DeemedSupplyRecords $records, Filesystem $files): int
    {
        $from = RecordsFile::day($this->option('from'));
        $to = RecordsFile::day($this->option('to'));

        if (! $from instanceof CarbonImmutable || ! $to instanceof CarbonImmutable || $to->lessThan($from)) {
            $this->components->error('Could not read the days; pass --from and --to as YYYY-MM-DD, the first not after the last.');

            return self::FAILURE;
        }

        [$contents, $count] = RecordsFile::render(DeemedSupplyRecords::COLUMNS, $records->between($from->startOfDay(), $to->endOfDay()));

        $path = $this->option('path');

        if (! is_string($path) || $path === '') {
            $this->line($contents);

            return self::SUCCESS;
        }

        $files->ensureDirectoryExists(dirname($path));
        $files->put($path, $contents);

        $this->components->info("{$count} record(s) written to {$path}.");

        return self::SUCCESS;
    }
}
