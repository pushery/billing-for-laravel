<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\PriceChanges\PriceChange;

/**
 * Tells the owner of a subscription that a declared price change applies to it from a day. A seam, like the trial and
 * dunning notifiers, so a host can route the mail through its own notification stack without the package knowing how.
 */
interface PriceChangeNotifier
{
    public function priceChangeAnnounced(Model $owner, PriceChange $change, DateTimeInterface $effectiveOn): void;
}
