<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * Whether a customer buys as a consumer or as a business.
 *
 * Consumer law protects the first and not the second, and an annual subscription continues differently for each:
 * a consumer's goes on month by month after its first year, a business's renews for another year.
 */
enum CustomerType: string
{
    case Consumer = 'consumer';

    case Business = 'business';
}
