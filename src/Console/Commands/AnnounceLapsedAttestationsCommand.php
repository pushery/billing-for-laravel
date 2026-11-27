<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Pushery\Billing\Marketplace\LapsedAttestationSweep;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Tell the merchants whose attestation ran out overnight.
 *
 * This is the only way that hold is ever noticed. Somebody recording a blocking standing writes a row, and a
 * write can be watched; an attestation expiring writes nothing at all — the hold begins because a date
 * passed. Without a scheduled sweep the merchant finds out by trying to sell.
 */
#[AsCommand(name: 'billing:tax-holds:announce')]
final class AnnounceLapsedAttestationsCommand extends Command
{
    protected $signature = 'billing:tax-holds:announce';

    protected $description = 'Announce tax holds that began because an attestation expired';

    public function handle(LapsedAttestationSweep $sweep): int
    {
        $announced = $sweep->announce(CarbonImmutable::now());

        $this->components->info(OutputFormatter::escape($announced === 0
            ? 'No attestation lapsed since the last run.'
            : "Announced {$announced} tax hold(s) from lapsed attestations."));

        return self::SUCCESS;
    }
}
