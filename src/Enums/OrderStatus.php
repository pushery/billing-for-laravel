<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * Where a local order sits in its lifecycle.
 *
 * An order is the package's own billing unit for a driver that has no provider-side order model (Mollie,
 * Adyen): a due cycle is assembled as an order, processed (its items summed, a charge attempted), and an
 * invoice is produced from the result. The status is the record of how far that got.
 */
enum OrderStatus: string
{
    /** Assembled but not yet processed — items may still be added. */
    case Open = 'open';

    /** A charge is in flight; the order is locked against further changes. */
    case Processing = 'processing';

    /** The charge succeeded; the invoice is produced from here. */
    case Paid = 'paid';

    /** The charge failed; the order is dunning's concern, not a settled sale. */
    case Failed = 'failed';

    /** A paid order that was refunded in full. */
    case Refunded = 'refunded';

    /** A paid order reversed by a credit note rather than a money refund. */
    case Credited = 'credited';
}
