<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Invoicing\Party;

/**
 * Resolves a merchant to the invoice PARTY it is — name, address, tax ids — for the documents the platform
 * issues about them.
 *
 * This is consumer data the package cannot know: a merchant is the consumer's own model, and its legal name
 * and registered address live in the consumer's schema, not here. So the package ships only the seam; a
 * marketplace binds a resolver that reads its merchants. It is distinct from {@see SellerPartyResolver},
 * which answers the seller of a document that already exists — this answers a merchant's identity BEFORE the
 * document is created, so it can be snapshotted onto it.
 *
 * There is no meaningful default: a self-billed document with no seller identity is not an invoice, so the
 * shipped binding fails closed rather than issue a nameless one. A single-seller install never calls this.
 */
interface MerchantPartyResolver
{
    public function partyFor(Model $merchant): Party;
}
