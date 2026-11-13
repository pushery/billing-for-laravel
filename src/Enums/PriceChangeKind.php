<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * The kind of price a declared price change names. Each kind carries its own notice, set in
 * `billing.price_changes.notice_months`, so a base price can need a longer notice than a smaller fee.
 */
enum PriceChangeKind: string
{
    /** The base price of a tier, `billing.tiers.<tier>.price_display`. */
    case Plan = 'plan';

    /** The price per package of a tier's meter, `billing.tiers.<tier>.metered.<meter>.unit_price`. */
    case Metered = 'metered';
}
