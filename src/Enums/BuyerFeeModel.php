<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * How a buyer fee is quantified.
 *
 * Both shapes are gross-inclusive: the buyer is quoted a fee that already contains its tax, because that is
 * the amount they actually pay, and the net and tax are read back OUT of it rather than added on top.
 */
enum BuyerFeeModel: string
{
    /** A share of the transaction the fee is charged on. */
    case Percent = 'percent';

    /** A flat amount per transaction, whatever the sale. */
    case Fixed = 'fixed';
}
