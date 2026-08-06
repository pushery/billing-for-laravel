<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Pushery\Billing\Contracts\SmallBusinessIdValidator;
use Pushery\Billing\Enums\VatIdValidation;

/**
 * The shipped default: no register is contacted, so nothing is ever confirmed.
 *
 * It answers `Unavailable` rather than `Invalid`, and the difference matters. `Invalid` would assert that
 * a registration was checked and found wanting — a statement about the merchant that this package is in no
 * position to make. `Unavailable` says only that nobody asked, which is the truth, and which leaves the
 * merchant unestablished rather than judged.
 *
 * The package makes no network call unless a consumer binds a real implementation. That keeps a bare
 * checkout working offline, and it keeps the package from reaching an external service nobody asked it to.
 */
final readonly class NullSmallBusinessIdValidator implements SmallBusinessIdValidator
{
    public function validate(?string $registrationId): VatIdValidation
    {
        return VatIdValidation::Unavailable;
    }
}
