<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

/**
 * Implemented by a team-owner model whose subscription quantity tracks seats.
 */
interface ProvidesSeats
{
    /** The number of seats the owner is entitled to / paying for. */
    public function seatCount(): int;

    /** The number of seats currently occupied. */
    public function occupiedSeatCount(): int;
}
