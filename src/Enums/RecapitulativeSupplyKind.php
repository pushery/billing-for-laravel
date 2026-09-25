<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * What a line of a recapitulative statement reports: supplies of goods or services.
 *
 * The statement keeps the two apart because the return does: an intra-community supply of goods and a service
 * whose buyer accounts for the tax are reported under separate markers, and a sum over both would be a figure
 * nobody asked for.
 */
enum RecapitulativeSupplyKind: string
{
    case Goods = 'goods';
    case Services = 'services';
}
