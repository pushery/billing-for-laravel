<?php

declare(strict_types=1);

namespace Pushery\Billing\Resolvers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\BillingEntityResolver;

/**
 * The one place the owner-vs-team decision lives. In "user" mode the acting user is its own billing
 * owner; in "team" mode the owner is the team returned by the configured relation on the user
 * (config `billing.team_relation`). It fails safe to the actor: if team mode is on but the relation
 * yields no model (a user without a team yet), the user owns billing rather than nothing — so a
 * half-configured tenancy never leaves an actor ownerless.
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

        return $owner instanceof Model ? $owner : $actor;
    }
}
