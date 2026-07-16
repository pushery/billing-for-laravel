<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\ValueObjects\Money;

/**
 * Delivers the dunning notice when a payment fails. Kept as a seam so the at-most-once send
 * discipline (the webhook effect) is decoupled from the notification content and channel — the
 * package ships a Laravel-notification implementation, but a consumer can swap in its own delivery.
 */
interface DunningNotifier
{
    public function paymentFailed(Model $owner, Money $amount, string $invoiceReference): void;
}
