<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * The name a buyer knows a merchant by, for what the buyer sees: the billing banner that names the creator whose
 * subscription needs attention.
 *
 * It is not the invoice party. {@see MerchantPartyResolver} answers with the name on the documents the platform
 * issues about a merchant, and on a creator marketplace that is the creator's legal name, given to the platform
 * and not to their fans. A surface a buyer reads asks this seam instead. The shipped binding knows no names and
 * answers null, and a surface then shows no name rather than the one from the documents.
 */
interface MerchantDisplayName
{
    /** The name the buyer knows this merchant by, or null when there is none to show. */
    public function nameFor(Model $merchant): ?string;
}
