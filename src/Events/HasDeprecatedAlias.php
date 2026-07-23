<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

/**
 * A domain event that was renamed and still fires under its former name for one deprecation window. The
 * effect bus dispatches the alias through the framework dispatcher ALONGSIDE the event, so a host app that
 * still `Event::listen`s for the old class keeps being called instead of going silently quiet — the worst
 * outcome of a rename. The alias is fired for host listeners only, never re-run through the package's own
 * registered effects, so nothing is persisted twice.
 */
interface HasDeprecatedAlias
{
    public function deprecatedAlias(): BillingDomainEvent;
}
