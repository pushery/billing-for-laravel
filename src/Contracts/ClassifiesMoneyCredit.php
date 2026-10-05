<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\Catalogs\ConfigAddonCatalog;

/**
 * An add-on catalog that says which of its add-ons credit money to the buyer's balance.
 *
 * Money credit is more than a balance: the credit is a voucher, its tax falls where the voucher's instrument type
 * says, and it counts toward the voucher volume an operator has to report. So only a catalog that says so makes an
 * add-on money credit. The shipped {@see ConfigAddonCatalog} answers for `billing.addons`; a catalog of your own
 * answers for its keys by implementing this, or by stating the `voucher` archetype for them. A key no catalog
 * classifies as credit credits nothing.
 */
interface ClassifiesMoneyCredit
{
    /** Whether buying this add-on credits its price to the buyer's balance. */
    public function isMoneyCredit(string $key): bool;
}
