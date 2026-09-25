<?php

declare(strict_types=1);

namespace Pushery\Billing\Invoicing;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\BuyerPartyResolver;

/**
 * The shipped default: it knows no customer, so it names none.
 *
 * Unlike a merchant, whose missing identity would leave a self-billed document without a seller, a customer
 * without a resolved name still gets a lawful document for the cases that need no name, and the cases that
 * need a VAT ID get it from the tax facts rather than from here. So this answers null instead of refusing,
 * and `billing:doctor` says when an installation that bills locally has bound nothing.
 */
final class NullBuyerPartyResolver implements BuyerPartyResolver
{
    public function partyFor(Model $owner): ?Party
    {
        return null;
    }
}
