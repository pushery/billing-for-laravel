<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * Where the odd minor unit of an uneven split lands.
 *
 * A percentage split of an integer amount rarely divides evenly — one minor unit is left over, and it has
 * to go to exactly one side. Which side is a real decision at volume (it is a cent per uneven transaction,
 * every transaction), so it is named explicitly rather than left to argument order.
 *
 * This is deliberately neutral money vocabulary: the value object knows a "portion" and a "remainder", not
 * a platform or a creator. The mapping from a fee policy (config `billing.marketplace.fee.rounding`, whose
 * default `platform_first` sends the residual to the fee portion) to one of these cases lives at the fee
 * call site, not here — Money carries no tax or marketplace meaning.
 */
enum RoundingResidual: string
{
    /** The leftover minor unit joins the bps PORTION (the first bucket of the split). */
    case ToPortion = 'to_portion';

    /** The leftover minor unit joins the REMAINDER (the second bucket of the split). */
    case ToRemainder = 'to_remainder';
}
