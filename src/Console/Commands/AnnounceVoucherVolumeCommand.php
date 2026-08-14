<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Pushery\Billing\Marketplace\VoucherVolumeSweep;

/**
 * Say when voucher volume has grown into something an operator has to act on.
 *
 * The monitor that computes the rolling figure had no caller at all, so a supervisory threshold could be
 * passed in silence: the package knew, and the person who has to file did not. This is the caller.
 *
 * Two levels, announced separately. "You are close" has a lead time attached and "you are past it" has a
 * deadline; a single message would let the first stand for the second.
 */
final class AnnounceVoucherVolumeCommand extends Command
{
    protected $signature = 'billing:vouchers:volume';

    protected $description = 'Announce voucher volume that has reached a supervisory threshold';

    public function handle(VoucherVolumeSweep $sweep): int
    {
        $announced = $sweep->announce(CarbonImmutable::now());

        $this->components->info($announced === 0
            ? 'No voucher volume has reached a level worth announcing.'
            : "Announced {$announced} voucher-volume level(s).");

        return self::SUCCESS;
    }
}
