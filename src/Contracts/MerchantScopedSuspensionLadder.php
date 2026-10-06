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
 * A ladder that picked a clock from among the owner's rows would be wrong whichever way it picked. Reading
 * the NEWEST row would let a fan two rungs deep reset to zero by subscribing to anybody, because a row with
 * no clock erases the ladder. Reading the EARLIEST would make the longest-standing debt govern every merchant
 * at once. Both are aggregates, and an aggregate is what turns a debt owed to A into a lockout at B.
 *
 * With a merchant there is nothing to pick: one relationship, one clock, one answer. A null scope is the
 * platform's own row, which in a single-seller install is every row there is.
 *
 * ## Who asks it
 *
 * Not the package. The route middleware asks the unscoped {@see SuspensionLadder}, which answers for the
 * platform's own row. This is the question for code of yours that knows the merchant. Ask the bound ladder,
 * and check that it implements this interface first: the shipped `LadderSuspension` does, and a ladder of
 * your own may not.
 */
interface MerchantScopedSuspensionLadder
{
    public function isLockedOutFor(Model $owner, string $surface, ?MerchantScope $merchant): bool;
}
