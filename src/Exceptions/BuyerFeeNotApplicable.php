<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use Pushery\Billing\Enums\SupplyRegime;
use RuntimeException;

/**
 * Buyer fees are switched on for a sale whose regime does not have one.
 *
 * ## Why this refuses instead of quietly charging nothing
 *
 * A buyer fee is the platform's own intermediation supply to the buyer. Under a commission chain there is
 * no mediation to charge for: the platform IS the seller and sells to the buyer directly, so a fee booked
 * there as intermediation revenue invents a supply relationship that does not exist. That is the mirror of
 * the defect the reporting record had, where a withheld fee was reported in the one regime that has none.
 *
 * Three outcomes were possible for "switched on, wrong regime", and charging nothing is the worst of them:
 * the setting reads as on, the operator believes they are collecting, and the first evidence otherwise is
 * revenue that never arrived. Refusing names which of the two conditions failed while it is still free to
 * fix — the same shape as every other fail-closed refusal in this package.
 *
 * Concatenation in this class assembles sentence text rather than behavior, so swapping or dropping
 * a fragment measures where the line was wrapped, not what a test asserts. The values themselves are
 * held by a dedicated guard that varies every parameter individually.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class BuyerFeeNotApplicable extends RuntimeException
{
    public function __construct(public readonly SupplyRegime $regime)
    {
        parent::__construct(
            "Buyer fees are enabled, but this sale is under the '{$regime->value}' regime, which has none. "
            .'A buyer fee is the platform\'s own intermediation supply TO THE BUYER, and it exists only where '
            .'the platform mediates a sale between two other parties. Under a commission chain the platform '
            .'is itself the seller, so a fee charged there would describe a service nobody rendered. Either '
            .'set billing.marketplace.buyer_fee.enabled to false, or sell under an intermediation regime — '
            .'and note that adding it to billing.marketplace.regime.allowed is a liability decision about '
            .'who contracts with the buyer, not a toggle.'
        );
    }

    public static function inRegime(SupplyRegime $regime): self
    {
        return new self($regime);
    }
}
