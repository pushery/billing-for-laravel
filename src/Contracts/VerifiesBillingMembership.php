<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Implemented by a billing owner that can say whether an actor still belongs to it.
 *
 * THIS EXISTS BECAUSE `billing.team_relation` MAY POINT AT A POINTER RATHER THAN A MEMBERSHIP.
 * `BillingEntityResolver` reads the configured relation off the actor, and for a relation that IS
 * the membership -- `$user->team` on a belongs-to -- the answer carries its own proof: the row
 * exists because the user belongs to it. A great many applications instead point at a mutable
 * column the user controls, `current_team_id` being the common one, and there the two questions
 * come apart: the pointer keeps answering after the user has been removed from the team.
 *
 * The package cannot tell the two apart from the outside, and guessing at method names on the
 * owner model would be the same mistake wearing a different hat. So the owner declares it. A model
 * that implements this is asked before it is billed; one that does not is trusted, which is the
 * behavior every consumer has today.
 *
 * Measured in a consuming application on 2026-09-19: its own resolver re-verified membership and
 * carried the reason in its docblock -- "a stale current_team_id can never bill a team the user is
 * not part of" -- while the package it was about to adopt did not ask. Adopting without this seam
 * would have deleted the check along with the code that held it, and no test would have gone red.
 */
interface VerifiesBillingMembership
{
    /**
     * Whether this owner may be billed on the actor's behalf.
     *
     * Answer `false` for an actor that no longer belongs, and the resolver falls back to the actor
     * itself -- the same direction it already takes when team mode yields no team at all, so a
     * removed member is never billed through a team and never left ownerless either.
     */
    public function hasBillingMember(Model $actor): bool;
}
