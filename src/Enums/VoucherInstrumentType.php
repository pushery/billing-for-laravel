<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * Whether a voucher's tax is settled when it is sold or when it is used.
 *
 * The difference is whether the supply behind it is already known. Where a platform sells into many
 * countries at many rates, neither the place nor the rate is fixed at issue, so nothing is taxed yet and the
 * voucher is money held against a promise. Where there is exactly one country and one rate, the supply IS
 * known, and the tax falls at issue.
 *
 * The type is frozen when the voucher is sold: it decides when the tax falls, and a supply already made
 * cannot be re-decided by a later configuration change. A consumer who changes their country footprint
 * changes the default for NEW vouchers, never for ones already out.
 */
enum VoucherInstrumentType: string
{
    /** Place and rate unknown at issue: nothing taxed yet, and the tax follows what is eventually bought. */
    case MultiPurpose = 'multi_purpose';

    /** The supply is already determined at issue, so the tax falls there. */
    case SinglePurpose = 'single_purpose';

    /** Whether issuing this voucher is itself a taxable supply. */
    public function taxedAtIssue(): bool
    {
        return $this === self::SinglePurpose;
    }
}
