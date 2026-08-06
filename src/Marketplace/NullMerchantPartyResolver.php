<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\MerchantPartyResolver;
use Pushery\Billing\Exceptions\MerchantPartyUnavailable;
use Pushery\Billing\Invoicing\Party;

/**
 * The shipped default: it knows no merchant, so it refuses rather than invent one.
 *
 * A merchant's identity is the consumer's data; there is no benign default a package could return, because a
 * self-billed document with an empty seller is a broken document, not a lesser one. So this fails closed —
 * a single-seller install never reaches it (it never self-bills), and a marketplace that does must bind a
 * resolver that reads its own merchants.
 */
final class NullMerchantPartyResolver implements MerchantPartyResolver
{
    public function partyFor(Model $merchant): Party
    {
        throw MerchantPartyUnavailable::forMerchant($merchant);
    }
}
