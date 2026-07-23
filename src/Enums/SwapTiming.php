<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * When a plan swap takes effect.
 *
 * An upgrade takes effect NOW — the customer asked for more and pays the prorated difference for the rest
 * of the cycle. A downgrade takes effect at the PERIOD END by default: the customer has already paid for
 * the current cycle at the higher tier, so moving them down mid-cycle either owes them a refund or takes
 * away what they paid for. Scheduling it to the boundary avoids both and matches what customers expect.
 *
 * The default is only a default. The direction is deterministic (it comes from the tier ranking), but
 * whether a downgrade waits is a product decision an application can override per its own norms.
 */
enum SwapTiming: string
{
    case Immediate = 'immediate';
    case PeriodEnd = 'period_end';
}
