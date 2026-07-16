<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Nudges the owner to confirm a payment their bank held for Strong Customer Authentication (3-D Secure).
 * The seam an app rebinds when this prompt should go somewhere other than Laravel's mail stack.
 *
 * The once-per-invoice guarantee lives in the caller (the webhook effect dedups on the invoice awaiting
 * authentication, not the delivery), so an implementation simply delivers.
 */
interface PaymentActionNotifier
{
    public function paymentActionRequired(Model $owner, string $invoiceReference): void;
}
