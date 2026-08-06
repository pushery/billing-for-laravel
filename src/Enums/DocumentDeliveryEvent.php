<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * What happened to a settlement document on its way to the person it settles with.
 *
 * Three events, and their asymmetry is the point. Making a document available and telling its recipient are
 * together what delivers it — that is the whole obligation. Fetching it is evidence ON TOP, not part of the
 * obligation: a recipient who never opens what they were handed has still been handed it, and a system that
 * treated an unfetched document as undelivered would keep re-issuing documents that were already valid.
 *
 * A failed notification is its own event rather than the absence of one. An absence cannot be distinguished
 * from a notification that was never attempted, and those are opposite situations: one needs a retry, the
 * other needs someone to find out why nothing tried.
 */
enum DocumentDeliveryEvent: string
{
    /** The document is where the recipient can reach it. */
    case Provided = 'provided';

    /** The recipient was told it is there. */
    case Notified = 'notified';

    /** Telling them did not work — a bounce, a rejected address, a transport error. */
    case NotificationFailed = 'notification_failed';

    /** They fetched it. Recorded because it strengthens the proof, never because it carries it. */
    case Retrieved = 'retrieved';

    /** Whether this event is one of the two that together make a document delivered. */
    public function completesDelivery(): bool
    {
        return $this === self::Provided || $this === self::Notified;
    }
}
