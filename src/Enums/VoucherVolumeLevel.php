<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * How far voucher volume has grown against the figure a supervisor cares about.
 *
 * Two levels, and they are different messages rather than degrees of one. `Approaching` says there is still
 * time to act; `Breached` says there is not. A marker that let one stand for the other would mean the only
 * warning that mattered never arrived — so both are announced, and both are remembered separately.
 */
enum VoucherVolumeLevel: string
{
    /** Close enough that there is still time to do something about it. */
    case Approaching = 'approaching';

    /** Past the figure at which a filing is expected. */
    case Breached = 'breached';
}
