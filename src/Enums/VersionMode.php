<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * WHICH version a grant resolves to right now — the computed instruction, not the promise it came from.
 *
 * Four update policies collapse into three modes here, and that is the point of having both types. A policy
 * is what the creator sold ("you get updates for a year"); a mode is what a delivery path must do TODAY
 * ("hand over the newest one", "hand over exactly this one", "hand over the newest one from before this
 * date"). The same policy yields different modes on different days — a windowed grant is `Latest` while its
 * window is open and `Bounded` once it closes — which is exactly why the delivery path must not be handed
 * the policy and asked to work it out.
 */
enum VersionMode: string
{
    /** Whatever is newest. */
    case Latest = 'latest';

    /** One exact version, named on the grant, whatever has been published since. */
    case Pinned = 'pinned';

    /** The newest version from before a date — a window that has closed. */
    case Bounded = 'bounded';
}
