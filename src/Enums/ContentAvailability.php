<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * Whether the work behind a reference can actually be handed over right now.
 *
 * ## Availability is not access, and keeping them apart is the whole point
 *
 * A grant and its work are two independent facts, and every combination of them is real: you own something
 * that has been taken down, you own something that has not been released, you own something you can read.
 * Folding availability into `granted` would make the first two look like "you do not own this" — which is a
 * refund conversation, a support ticket, and a lie.
 *
 * So access to a work that is not there is a STATE, not an error. The library screen says "you own this,
 * it is currently unavailable"; nothing throws.
 *
 * The package never decides this itself. It owns money and ownership; the consumer owns the content, so the
 * answer comes from the consumer's catalog seam and is carried through unchanged.
 */
enum ContentAvailability: string
{
    /** The work is there and can be handed over. */
    case Available = 'available';

    /**
     * It was there and is not any more — withdrawn, taken down, the creator's account deleted.
     *
     * The grant is untouched. Somebody owning a work whose publication ended is the ordinary case, and the
     * record of that ownership is what a refund, a dispute or a legal request is settled against.
     */
    case ContentGone = 'content_gone';

    /** It exists but has not been released yet — a pre-order, bought before the work is handed over. */
    case NotYet = 'not_yet';

    /** Whether the work can be handed over now. Named for the question a delivery path actually asks. */
    public function isDeliverable(): bool
    {
        return $this === self::Available;
    }
}
