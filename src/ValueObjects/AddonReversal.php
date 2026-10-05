<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Illuminate\Database\Eloquent\Model;

/**
 * The result of reversing an add-on purchase: the owner whose credit to claw back, and the DELTA
 * actually reversed this time (never more than the purchase, never a re-reversal of what a redelivered
 * refund already undid). The reversal effect debits exactly this.
 *
 * It also carries what the purchase WAS — its add-on key and the amount originally paid — because an
 * add-on that granted usage UNITS rather than money has to claw back units, and the only honest way to
 * turn a partial money refund into units is proportionally: refunding half of what was paid takes back
 * half of what was granted.
 *
 * And whether the purchase put money on the balance and which units it granted, as recorded when the purchase was
 * made, so the refund takes back what the purchase did even where the catalog has changed since. Both are null for a
 * purchase recorded without them, and the units are also null for a purchase that granted none.
 */
final readonly class AddonReversal
{
    public function __construct(
        public Model $owner,
        public Money $amount,
        public string $addonKey = '',
        public int $purchaseMinor = 0,
        public ?bool $moneyCredit = null,
        public ?UnitGrant $granted = null,
    ) {}
}
