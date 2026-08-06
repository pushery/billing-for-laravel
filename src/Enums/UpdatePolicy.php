<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * Which version of a work an owner is entitled to as it changes.
 *
 * A bought work is not frozen the way a delivered parcel is: the creator keeps editing it. What the buyer
 * gets afterwards is a promise made at the sale, so it is stored on the grant rather than read from the
 * work — the creator may change their policy tomorrow, and that must not rewrite what somebody already
 * bought.
 */
enum UpdatePolicy: string
{
    /** Always the current version, forever. The most generous, and the default a buyer assumes. */
    case Latest = 'latest';

    /** The current version, but only within the same revision line — a rewrite is a new work. */
    case LatestWithRevisions = 'latest_with_revisions';

    /** Updates until a stated date, then whatever was current at that moment. */
    case Windowed = 'windowed';

    /** Exactly the version bought, pinned. Later editions are separate purchases. */
    case Frozen = 'frozen';
}
