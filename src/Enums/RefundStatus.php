<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * Where one refund stands, as the provider reports it, in words that mean the same for every provider.
 */
enum RefundStatus: string
{
    /**
     * The provider accepted the refund and the money is not with the customer yet. It can still fail or be
     * canceled, so nothing that depends on the customer having the money, such as a credit note, is final yet.
     */
    case Pending = 'pending';

    /** The money reached the customer. */
    case Refunded = 'refunded';

    /** The refund failed after the provider accepted it, and the money stayed with the merchant. */
    case Failed = 'failed';

    /** The refund was canceled before it was paid out, and the money stayed with the merchant. */
    case Canceled = 'canceled';
}
