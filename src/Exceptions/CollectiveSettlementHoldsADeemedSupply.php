<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use Pushery\Billing\Enums\DeemedSupplyOfGoods;
use RuntimeException;

/**
 * A month's transactions for one creator hold a supply of goods the platform is treated as making itself.
 *
 * The seller's supply in such a sale is documented on its own. For goods already in the Union it is exempt where the
 * goods were when the transport began and names the seller's registration in that member state, and goods sold from
 * two warehouses in one month are two registrations no single document can name. For an imported consignment it is a
 * settlement note of the payout, beside the self-billed invoices of the rest of the month. A collective document that
 * took the sale in would state the treatment of the other lines for it.
 *
 * Concatenation in this class assembles sentence text rather than behavior, so swapping or dropping
 * a fragment measures where the line was wrapped, not what a test asserts. The values themselves are
 * held by a dedicated guard that varies every parameter individually.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class CollectiveSettlementHoldsADeemedSupply extends RuntimeException
{
    public static function make(string $period, DeemedSupplyOfGoods $supply): self
    {
        return new self(
            "The collective settlement for period [{$period}] holds a sale the platform is treated as supplying itself "
            ."([{$supply->value}], {$supply->provision()}). Settle that sale on its own with SelfBillingEngine::issue(), "
            .'which documents the seller\'s supply under the registration where the goods were, and leave it out of the '
            .'month\'s collective document.'
        );
    }
}
