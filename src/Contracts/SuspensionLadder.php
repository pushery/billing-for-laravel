<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * The per-surface suspension ladder: whether a given surface is currently locked out for a
 * delinquent owner. The delinquency clock is a timestamp, never a gateway status, so lockout is
 * outage-safe.
 *
 * ## This signature is deliberately NOT merchant-scoped
 *
 * Arrears belong to a relationship, so the useful question names a merchant — and this method has nowhere
 * to put one. Appending an optional parameter was tried and rejected on evidence: it fatals every existing
 * implementation at the declaration, including the ones declared inline in this package's own suite. That
 * is a MAJOR break for a MINOR release.
 *
 * The scoped question therefore lives on a separate, optional interface — {@see MerchantScopedSuspensionLadder}.
 */
interface SuspensionLadder
{
    public function isLockedOut(Model $owner, string $surface): bool;
}
