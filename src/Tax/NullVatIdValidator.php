<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Pushery\Billing\Contracts\VatIdValidator;
use Pushery\Billing\Enums\VatIdValidation;

/**
 * The default validator: it proves nothing (always Unavailable), so the package boots and runs offline and in
 * tests without reaching for VIES. Because Unavailable is treated conservatively, an unconfigured install
 * never grants the reverse charge on an unvalidated id — it simply charges VAT until an app binds the
 * VIES-backed validator.
 */
final class NullVatIdValidator implements VatIdValidator
{
    public function validate(?string $vatId): VatIdValidation
    {
        return VatIdValidation::Unavailable;
    }
}
