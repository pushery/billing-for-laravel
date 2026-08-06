<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Pushery\Billing\Marketplace\UnestablishedStandingSweep;

/**
 * Tell the merchants who never declared that the deadline is coming.
 *
 * The configuration beside `enforce_from` says to do exactly this — "tell the merchants who are missing
 * one, and let the date arrive" — and nothing did. The date arrives on its own; the telling needed code.
 *
 * Without it, the first a merchant hears of the deadline is a refused sale: at the till, having done
 * nothing and been asked for nothing, at the one moment where a declaration cannot be produced in time.
 */
final class WarnUnestablishedStandingsCommand extends Command
{
    protected $signature = 'billing:tax-holds:warn';

    protected $description = 'Warn merchants who have not declared a tax standing that the deadline is approaching';

    public function handle(UnestablishedStandingSweep $sweep): int
    {
        $warned = $sweep->warn(CarbonImmutable::now());

        $this->components->info($warned === 0
            ? 'Nobody needed warning about the tax-standing deadline.'
            : "Warned {$warned} merchant(s) that the tax-standing deadline is approaching.");

        return self::SUCCESS;
    }
}
