<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * The per-surface suspension ladder: whether a given surface is currently locked out for a
 * delinquent owner. The delinquency clock is a timestamp, never a gateway status, so lockout is
 * outage-safe.
 */
interface SuspensionLadder
{
    public function isLockedOut(Model $owner, string $surface): bool;
}
