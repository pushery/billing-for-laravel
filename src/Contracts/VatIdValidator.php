<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\Enums\VatIdValidation;

/**
 * Validates an EU VAT identification number — proves an intra-EU business is who it claims before its supply
 * is zero-rated under the reverse charge. The default binding never calls out (so the package works offline
 * and in tests); an app that needs real validation binds the VIES-backed implementation.
 */
interface VatIdValidator
{
    public function validate(?string $vatId): VatIdValidation;
}
