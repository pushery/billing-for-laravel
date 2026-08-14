<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Which currencies a party has actually earned in.
 *
 * ## The question the read layer could not answer
 *
 * Every per-merchant reader takes the currency as a REQUIRED parameter — `availableFor($party, $currency)`,
 * `pendingFor`, `heldFor`, `earnedIn`, `countedIn`, `reportsFor`. That is right: a currency is a bucket and
 * is never converted. But it left "show me my balance per currency" unanswerable, because nothing said which
 * buckets exist for a given party.
 *
 * A consumer building a creator dashboard therefore had exactly one route: query `billing_merchant_charges`
 * directly, around the read layer, coupling their application to a schema this package owns and changes.
 *
 * ## Why the configured currency list is not the answer
 *
 * `billing.tax_exchange_rates.currencies` looks like it, and a loop over it is silently incomplete: it lists
 * the currencies rates are IMPORTED for, which its own configuration comment separates in as many words from
 * the currencies money is settled in. A creator with earnings in a currency nobody imports rates for would
 * simply be shown one balance fewer, with nothing anywhere going red.
 *
 * ## Why a sibling contract rather than a method on the balance reader
 *
 * {@see LedgerBalanceReader} is documented as implementable BY a consumer. Adding a method to it would be a
 * fatal error in every such implementation on the next update — the interface would demand something their
 * class does not have. A separate contract is additive: an existing implementation keeps working untouched,
 * and a consumer who wants the enumeration binds this one too.
 */
interface ListsEarningCurrencies
{
    /**
     * Every currency this party has earned in, uppercase and sorted, with no duplicates.
     *
     * Empty for a party with no earnings — an answer, not an error. A merchant who has not sold yet is the
     * ordinary starting state, and a caller rendering "no balances" needs a list to iterate rather than a
     * null to guard.
     *
     * @return list<string>
     */
    public function currenciesFor(Model $party): array;
}
