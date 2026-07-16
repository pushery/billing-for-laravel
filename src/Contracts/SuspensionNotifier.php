<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\ValueObjects\Money;

/**
 * Delivers the escalating suspension warning as an owner climbs the dunning ladder, before a surface is
 * locked out. A SEPARATE seam from DunningNotifier (which fires once, on the payment failure) so that
 * adding the escalation never touches the published one-method DunningNotifier contract — a consumer
 * that implemented DunningNotifier keeps working. The package ships a Laravel-notification default; a
 * consumer can swap in its own delivery.
 */
interface SuspensionNotifier
{
    public function suspensionWarning(Model $owner, Money $amountDue): void;
}
