<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\MerchantResolver;

/**
 * The shipped default: no merchant, so every checkout is a platform sale.
 *
 * It is the single-seller answer, not a placeholder. A package that guessed a merchant from ambient state
 * would route money on the strength of nothing; one that demanded a merchant would break every install that
 * has none. So the default says "this sale is the platform's own", and a marketplace binds a resolver that
 * reads its own context.
 */
final readonly class NullMerchantResolver implements MerchantResolver
{
    public function current(): ?Model
    {
        return null;
    }
}
