<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * Where a one-off payment into a payment account stands, as the provider reported it last.
 */
enum AccountPaymentStatus: string
{
    /** The customer has started paying, and the provider waits for the money, as with a bank transfer. */
    case Pending = 'pending';

    /** The provider holds the amount and captures it later, as Klarna does until the order ships. */
    case Authorized = 'authorized';

    case Paid = 'paid';

    /** Part of the amount went back to the customer. */
    case PartiallyRefunded = 'partially_refunded';

    /** The whole amount went back to the customer. */
    case Refunded = 'refunded';

    /** The newest refund failed, and the money stayed with the merchant. */
    case RefundFailed = 'refund_failed';

    /** The customer's bank took money back from a paid payment. */
    case ChargedBack = 'charged_back';

    case Failed = 'failed';

    case Canceled = 'canceled';

    case Expired = 'expired';
}
