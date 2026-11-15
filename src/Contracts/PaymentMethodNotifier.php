<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\ValueObjects\PaymentMethod;

/**
 * Sends the warning that an owner's default card is about to expire. A seam, like the trial and dunning
 * notifiers, so a host can route the mail through its own notification stack without the package knowing how.
 */
interface PaymentMethodNotifier
{
    public function cardExpiring(Model $owner, PaymentMethod $method): void;
}
