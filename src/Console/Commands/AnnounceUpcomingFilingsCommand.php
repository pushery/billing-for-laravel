<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Pushery\Billing\Tax\UpcomingFilingSweep;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Warn about each filing obligation before its day arrives.
 *
 * The calendar that computes the dates says its purpose is to keep the day from arriving unannounced, and
 * nothing called it — so the day arrived exactly as unannounced as before. This is the caller.
 *
 * Two obligations share the end-of-January date, and each gets its own warning. A single "something is due"
 * would let whoever handles the one they thought of consider the day dealt with.
 */
#[AsCommand(name: 'billing:filings:announce')]
final class AnnounceUpcomingFilingsCommand extends Command
{
    protected $signature = 'billing:filings:announce';

    protected $description = 'Warn about filing obligations falling due inside the notice window';

    public function handle(UpcomingFilingSweep $sweep): int
    {
        $announced = $sweep->announce(CarbonImmutable::now());

        $this->components->info(OutputFormatter::escape($announced === 0
            ? 'No filing obligation falls due inside the notice window.'
            : "Announced {$announced} upcoming filing obligation(s)."));

        return self::SUCCESS;
    }
}
