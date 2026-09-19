<?php

declare(strict_types=1);

namespace Pushery\Billing\Resolvers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\BillingEntityResolver;
use Pushery\Billing\Contracts\VerifiesBillingMembership;

/**
 * The one place the owner-vs-team decision lives. In "user" mode the acting user is its own billing
 * owner; in "team" mode the owner is the team returned by the configured relation on the user
 * (config `billing.team_relation`). It fails safe to the actor: if team mode is on but the relation
 * yields no model (a user without a team yet), the user owns billing rather than nothing — so a
 * half-configured tenancy never leaves an actor ownerless.
 *
 * AND IT ASKS A TEAM THAT CAN ANSWER WHETHER THE ACTOR STILL BELONGS TO IT. The configured
 * relation is read off the actor, which proves membership when the relation IS the membership and
 * proves nothing when it is a pointer the user controls -- `current_team_id` and its kind keep
 * answering after the user has been removed. An owner implementing {@see VerifiesBillingMembership}
 * is asked; one that does not is trusted, which is what every consumer gets today. A refusal falls
 * back to the actor, the same direction as "team mode yields no team", so a removed member is
 * neither billed through the team nor left ownerless.
 */
final readonly class ConfigBillingEntityResolver implements BillingEntityResolver
{
    public function __construct(private Repository $config) {}

    public function ownerFor(Model $actor): Model
    {
        if ($this->config->get('billing.owner') !== 'team') {
            return $actor;
        }

        $relation = $this->config->get('billing.team_relation', 'team');
        $relation = is_string($relation) ? $relation : 'team';

        $owner = $actor->getAttribute($relation);

        if (! $owner instanceof Model) {
            return $actor;
        }

        if ($owner instanceof VerifiesBillingMembership && ! $owner->hasBillingMember($actor)) {
            return $actor;
        }

        return $owner;
    }
}
