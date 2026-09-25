<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * Where one settlement stands after its creator's standing was corrected.
 */
enum SettlementRestatementState: string
{
    /** Queued by the change and not yet looked at. */
    case Pending = 'pending';

    /** Canceled and issued again under the standing now in force. */
    case Restated = 'restated';

    /**
     * Looked at and left alone: under the standing now in force it states exactly what it states already.
     *
     * A standing can move between two that produce the same document, such as a business in the union and
     * one outside it, both reverse-charged. Canceling and re-issuing an identical document would put two
     * bookings and two numbers on a supply that needed neither.
     */
    case Unchanged = 'unchanged';

    /** Could not be issued again, for the reason recorded beside it. Nothing was canceled. */
    case Blocked = 'blocked';
}
