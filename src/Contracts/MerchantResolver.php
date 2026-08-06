<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * The merchant a routed checkout is FOR — the seller a buyer is paying, resolved implicitly from the
 * application's own context rather than passed in at the call site.
 *
 * Implicit on purpose. A fan subscribes from a creator's page, and the creator is context the app already
 * holds — the tenant, the profile being viewed, the route binding. Threading it through every `subscribe()`
 * call would make the single-seller call site and the marketplace one differ, so the SAME call is routed or
 * not depending only on what this resolver answers.
 *
 * The shipped default answers null — the platform itself, an unrouted sale — so an install that never binds
 * a real resolver behaves exactly as a single seller, and the checkout consults it only when the marketplace
 * is switched on. A marketplace binds its own resolver that reads its context.
 */
interface MerchantResolver
{
    /** The merchant the current checkout routes to, or null for an unrouted platform sale. */
    public function current(): ?Model;
}
