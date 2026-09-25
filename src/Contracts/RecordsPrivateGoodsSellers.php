<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\ValueObjects\SellerRecordField;

/**
 * A reporting profile whose regime requires a record of every private individual who sells goods through the
 * platform, before they may sell.
 *
 * Which fields that record holds is the regime's answer, not the package's: a regime that keeps no such
 * record binds a profile without this contract, and nothing is asked. The fields come from the same catalog
 * the reporting record uses, so a platform collects them once, through one form, into one place.
 */
interface RecordsPrivateGoodsSellers
{
    /** @return list<SellerRecordField> */
    public function fieldsForPrivateGoodsSeller(): array;
}
