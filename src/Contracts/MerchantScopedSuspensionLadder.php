<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\ValueObjects\MerchantScope;

/**
 * The suspension ladder asked about ONE merchant: whether this surface is withdrawn for this owner in this
 * relationship.
 *
 * ## Why this is its own interface rather than a parameter on {@see SuspensionLadder}
 *
 * Same reason as its dunning sibling, and the same evidence: appending an optional parameter to a published
 * interface fatals every existing implementation at the declaration, before any call. A consumer who bound
 * their own ladder would meet that on a MINOR upgrade. An optional sibling costs them nothing.
 *
 * ## What the scope removes
 *
 * The unscoped ladder has to pick a clock from among the owner's rows, and every way of picking is wrong.
 * Reading the NEWEST row let a fan two rungs deep reset to zero by subscribing to anybody — a row with no
 * clock erased the ladder. Reading the EARLIEST fixed the reset and made the longest-standing debt govern
 * every merchant at once. Both are aggregates, and an aggregate is what turns a debt owed to A into a
 * lockout at B.
 *
 * With a merchant there is nothing to pick: one relationship, one clock, one answer. A null scope is the
 * platform's own row, which in a single-seller install is every row there is.
 */
interface MerchantScopedSuspensionLadder
{
    public function isLockedOutFor(Model $owner, string $surface, ?MerchantScope $merchant): bool;
}
