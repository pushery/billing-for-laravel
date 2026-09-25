<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Invoicing\Party;

/**
 * Resolves the customer an invoice of the local engine is addressed to: name and address, or nothing.
 *
 * A provider-driven document copies its buyer from the provider, and a marketplace receipt is handed one by
 * the code that asks for it. An invoice the local engine raises has neither source. The customer is the
 * application's own model, and its legal name and address live in the application's schema, so the package
 * ships the seam and a default that knows nobody.
 *
 * Returning null is an answer, not a failure. The tax facts the package established itself reach the
 * document without it: a buyer whose VAT ID a register confirmed is named by that ID and the country it
 * registers them in, because a zero-rated supply is not a complete invoice without them.
 *
 * Called once per invoice, when it is raised. What it returns is frozen onto the document, so a later change
 * of address rewrites no invoice that was already issued.
 */
interface BuyerPartyResolver
{
    public function partyFor(Model $owner): ?Party;
}
