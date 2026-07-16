<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * The outcome of validating an EU VAT id against VIES. `Unavailable` is deliberately distinct from `Invalid`:
 * VIES being unreachable is NOT a proof the id is bad, so a caller treats it conservatively (do not grant the
 * reverse-charge zero-rate on an unproven id — never under-charge VAT) rather than as a rejection.
 */
enum VatIdValidation: string
{
    case Valid = 'valid';
    case Invalid = 'invalid';
    case Unavailable = 'unavailable';

    /** Whether the id was PROVEN valid — the only outcome that may grant the intra-EU reverse charge. */
    public function isValid(): bool
    {
        return $this === self::Valid;
    }
}
