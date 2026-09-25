<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * Where a sale put on a card reader stands.
 *
 * A declined card is not a state of its own: the payment stays open on the reader and can be presented again, so
 * the sale stays pending until it is paid or taken off the reader.
 */
enum InPersonSaleStatus: string
{
    /** On the reader, or put there and not yet paid. */
    case Pending = 'pending';

    /** The provider confirmed the payment, and the sale has its receipt. */
    case Paid = 'paid';

    /** Taken off the reader before it was paid. Nothing was supplied against it, so it has no receipt. */
    case Canceled = 'canceled';
}
