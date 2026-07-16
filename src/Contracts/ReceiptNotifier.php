<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\ValueObjects\Money;

/**
 * Tells the owner a payment went through — the receipt half of the money conversation. The package tells
 * a customer when their money did NOT move (the dunning notice); this is the other side, and it is the
 * seam an app rebinds when its receipts should go somewhere other than Laravel's mail stack (an
 * accounting system, a document service, its own template).
 *
 * The once-per-payment guarantee lives in the caller (the webhook effect dedups on the payment reference,
 * not on the delivery), so an implementation simply delivers.
 */
interface ReceiptNotifier
{
    public function paymentSucceeded(Model $owner, Money $amount, string $reference): void;
}
