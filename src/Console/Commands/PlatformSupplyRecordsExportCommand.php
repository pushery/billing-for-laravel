<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Pushery\Billing\Support\RecordsFile;
use Pushery\Billing\Tax\PlatformSupplyRecords;

/**
 * Writes the records of the supplies the platform facilitated without being their supplier, for the days asked for.
 *
 * A platform that facilitates supplies through its interface keeps records of them and makes them available
 * electronically to the member states where they are taxable (Art. 242a VAT Directive). This is that export: one row
 * per routed sale arranged in the range, with the content Art. 54c(2) of Implementing Regulation 282/2011 lists, as
 * {@see PlatformSupplyRecords} describes the columns. The supplier's identity comes from the application's
 * `MerchantPartyResolver`, so a column it cannot answer stays empty and is the application's to supply.
 *
 * The file is semicolon-separated, and a field holding a semicolon, a quote or a line break is quoted.
 */
final class PlatformSupplyRecordsExportCommand extends Command
{
    protected $signature = 'billing:platform-records:export
        {--from= : The first day, as YYYY-MM-DD}
        {--to= : The last day, as YYYY-MM-DD}
        {--path= : Write the file here instead of to the terminal}';

    protected $description = 'Export the records of the supplies the platform facilitated without being their supplier';

    public function handle(PlatformSupplyRecords $records, Filesystem $files): int
    {
        $from = RecordsFile::day($this->option('from'));
        $to = RecordsFile::day($this->option('to'));

        if (! $from instanceof CarbonImmutable || ! $to instanceof CarbonImmutable || $to->lessThan($from)) {
            $this->components->error('Could not read the days; pass --from and --to as YYYY-MM-DD, the first not after the last.');

            return self::FAILURE;
        }

        [$contents, $count] = RecordsFile::render(PlatformSupplyRecords::COLUMNS, $records->between($from->startOfDay(), $to->endOfDay()));

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
