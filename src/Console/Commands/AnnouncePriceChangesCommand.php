<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Carbon;
use Pushery\Billing\PriceChanges\DeclaredPriceChanges;
use Pushery\Billing\PriceChanges\PriceChangeAnnouncer;

/**
 * Announces every declared price change that is due to the running subscriptions it reaches, once each.
 *
 * A change applies to the cycles the package bills itself, so it is announced where Mollie is the driver; a driver whose
 * provider prices its own invoices announces nothing, and `billing:doctor` reports a change declared there. A change that
 * breaks a rule is announced to nobody, and every rule it breaks is printed, which fails the run.
 */
final class AnnouncePriceChangesCommand extends Command
{
    protected $signature = 'billing:price-changes:announce';

    protected $description = 'Announce the declared price changes to the running subscriptions they reach.';

    public function handle(Repository $config, DeclaredPriceChanges $declared, PriceChangeAnnouncer $announcer): int
    {
        $refusals = $declared->refusals();

        foreach ($refusals as $problems) {
            foreach ($problems as $problem) {
                $this->components->error($problem);
            }
        }

        // The package bills the cycles of the Mollie driver itself, the only driver whose subscriptions its engine prices.
        if ($config->get('billing.default', 'stripe') !== 'mollie') {
            $this->components->info('Price changes apply to the cycles the package bills itself, and the active driver bills its own. Nothing was announced.');

            return $refusals === [] ? self::SUCCESS : self::FAILURE;
        }

        $result = $announcer->announce(Carbon::now(), 'mollie');

        $this->components->info("Announced {$result['announced']} price change(s) to subscriptions; {$result['failed']} could not be announced and are tried again on the next run.");

        return $refusals === [] && $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
