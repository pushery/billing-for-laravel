<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * How an attempt to pay what a subscription owes ended.
 */
enum OutstandingPaymentOutcome: string
{
    /** The subscription owes nothing: its latest invoice is not open, or the provider holds no subscription for it. */
    case NothingOwed = 'nothing_owed';

    /** The invoice is paid. The subscription is active again once the provider reports the payment. */
    case Paid = 'paid';

    /** The payment is on its way and not yet settled, such as a bank debit. */
    case Pending = 'pending';

    /** The payment method was declined. */
    case Declined = 'declined';

    /** The cardholder has to confirm the payment on the provider's page, for 3-D Secure. */
    case NeedsConfirmation = 'needs_confirmation';
}
