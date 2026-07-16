<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;

/**
 * Thrown when a seat sync would bill a team for FEWER seats than it currently has members occupying.
 *
 * Billing below the occupied count is a silent oversell: the team keeps every member working while paying
 * for fewer seats than it uses, and nobody notices until an audit — the exact under-billing this whole
 * seam exists to prevent. So a downgrade past the floor fails loudly rather than quietly shipping revenue
 * out the door. The floor is the OCCUPIED count, never the entitled count: a team may hold more seats than
 * it fills, but it may never fill more than it holds.
 */
final class SeatDowngradeBelowOccupied extends RuntimeException
{
    public static function for(int $requested, int $occupied): self
    {
        return new self(
            "Refusing to set the seat quantity to {$requested}: {$occupied} seat(s) are occupied, and billing ".
            'below the occupied count would silently under-bill the team. Remove members first, or keep the higher quantity.'
        );
    }
}
