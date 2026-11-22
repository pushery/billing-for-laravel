<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\ValueObjects\Money;

/**
 * A payment action notifier that also takes the amount the bank holds for confirmation, so the prompt can
 * name the payment it asks about. The webhook effect calls paymentActionRequiredWithAmount() on a notifier
 * that implements this where the provider named an amount, and paymentActionRequired() otherwise. A notifier
 * that implements only PaymentActionNotifier keeps working as it is.
 */
interface PaymentActionAmountNotifier extends PaymentActionNotifier
{
    public function paymentActionRequiredWithAmount(Model $owner, string $invoiceReference, Money $amount): void;
}
