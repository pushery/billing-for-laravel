<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\MerchantDisplayName;

/**
 * The shipped default: it knows no merchant's name, so it shows none.
 *
 * The name a fan knows a creator by is the application's data. Unlike an invoice, a notice without a name is a
 * lesser notice and not a broken one, so this answers null instead of refusing.
 */
final class NullMerchantDisplayName implements MerchantDisplayName
{
    public function nameFor(Model $merchant): ?string
    {
        return null;
    }
}
