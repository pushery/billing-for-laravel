<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * WHY somebody can reach a work right now — the answer to the second half of "does P have access, and
 * through what".
 *
 * ## Why this is not GrantSource
 *
 * The ticket that specified this reader asked for `via: GrantSource` and, two acceptance criteria later, for
 * `via = SUBSCRIPTION`. Both cannot hold: `GrantSource` deliberately has no subscription case, because
 * persisting one would freeze a state as a fact and leave, at the first cancellation, a row saying somebody
 * owns what they only ever rented.
 *
 * The way out is that these are two different questions. `GrantSource` is a COLUMN — how a row came to
 * exist, written once and never recomputed. This is an ANSWER — computed per read, never stored, and one of
 * its cases has no row behind it at all. Sharing one type would have made the impossible case reachable
 * exactly where it does the damage: on the write side.
 *
 * ## Ownership wins over a subscription
 *
 * When both say yes, this names ownership. Not because it is "more true", but because it is the stronger
 * claim to report: it survives the subscription ending, and a screen that said SUBSCRIPTION would tell the
 * reader their access ends with their plan when it does not.
 *
 * ## It says nothing about who sold anything
 *
 * This is an access fact, never a tax or liability one. The platform is the seller toward the buyer for both
 * shapes, and no consumer should read a revenue treatment, a place of supply, or a withdrawal type out of
 * this enum — those have their own vocabularies, and a second categorization derived from this one would
 * part company with them at the first correction.
 */
enum AccessVia: string
{
    /** A bought work, owned by the person reading it. */
    case Purchase = 'purchase';

    /** A bought work, paid for by somebody else. */
    case Gift = 'gift';

    /** Given rather than sold: a review copy, a goodwill grant, a prize. */
    case Comp = 'comp';

    /** Came with a bundle. */
    case Bundle = 'bundle';

    /**
     * Reachable because a subscription currently covers it — and only for as long as it does.
     *
     * There is no row behind this case. It is recomputed on every read from the live subscription state and
     * the consumer's own tier-to-content scope, which is why it can never be a `GrantSource`.
     */
    case Subscription = 'subscription';

    /** The access-side reading of a persisted grant's source. Total by construction: every source maps. */
    public static function fromGrantSource(GrantSource $source): self
    {
        return match ($source) {
            GrantSource::Purchase => self::Purchase,
            GrantSource::Gift => self::Gift,
            GrantSource::Comp => self::Comp,
            GrantSource::Bundle => self::Bundle,
        };
    }

    /**
     * Whether access through this source can be taken away, as opposed to merely running out.
     *
     * A grant is revoked — by a refund, a chargeback, a legal takedown — and that is an act somebody
     * performs, recorded with a reason. A subscription is not revoked; it lapses. Collapsing the two would
     * make "why did this person lose access" unanswerable for the case where nobody did anything.
     */
    public function isRevocable(): bool
    {
        return $this !== self::Subscription;
    }
}
