<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Resolves who owns billing for an actor — the actor itself (user-owner model) or its team
 * (team-owner model), driven by config('billing.owner'). This is the single place the owner-vs-team
 * decision lives, so nothing else has to branch on it.
 */
interface BillingEntityResolver
{
    public function ownerFor(Model $actor): Model;
}
