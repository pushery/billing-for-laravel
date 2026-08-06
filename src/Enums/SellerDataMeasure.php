<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * What a platform does once reminders have run out.
 *
 * Two very different impositions, and which one is appropriate is a platform's call rather than a package
 * default: stopping somebody selling ends their income going forward, while holding their money leaves
 * them selling and unpaid. Neither is obviously the gentler one.
 */
enum SellerDataMeasure: string
{
    /** They may not sell until they supply the data. */
    case SuspendSales = 'suspend_sales';

    /**
     * They keep selling; the money waits.
     *
     * Held, never forfeited — and never open-ended: the rail the money sits on has its own limit, and a
     * hold that outlasted it would stop being a hold and start being a problem with the money itself.
     */
    case WithholdPayout = 'withhold_payout';
}
