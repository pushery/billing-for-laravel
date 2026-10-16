<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * What a line of a payment is, in the terms a provider that takes order lines uses.
 */
enum PaymentLineType: string
{
    case Physical = 'physical';
    case Digital = 'digital';
    case ShippingFee = 'shipping_fee';
    case Discount = 'discount';
    case StoreCredit = 'store_credit';
    case GiftCard = 'gift_card';
    case Surcharge = 'surcharge';
}
