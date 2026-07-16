<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\ValueObjects\Money;

/**
 * Applies a dunning late fee so it is collected with the owner's NEXT invoice. A seam because HOW a fee
 * is collected is a driver concern — Stripe adds a pending invoice item, a local-engine driver posts it
 * to the owner's balance. The reference is a stable idempotency key so re-running the dunning advance
 * cannot charge the same fee twice. The package binds a no-op default; a driver that can bill a fee
 * replaces it.
 */
interface LateFees
{
    public function apply(Model $owner, Money $fee, string $reference, string $description): void;
}
