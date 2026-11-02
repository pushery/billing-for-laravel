<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Pushery\Billing\Contracts\ChecksVatIds;
use Pushery\Billing\Contracts\VatIdValidator;
use Pushery\Billing\ValueObjects\VatIdCheck;

/**
 * Answers {@see ChecksVatIds} for a bound {@see VatIdValidator} that does not read the register itself: its verdict,
 * and no detail. An application with a validator of its own keeps it, and check() gives the verdict validate() gives.
 */
final readonly class VerdictOnlyVatIdChecks implements ChecksVatIds
{
    public function __construct(private VatIdValidator $validator) {}

    /** The validator itself where it reads the register, else its verdict alone. */
    public static function of(VatIdValidator $validator): ChecksVatIds
    {
        return $validator instanceof ChecksVatIds ? $validator : new self($validator);
    }

    public function check(?string $vatId, ?string $requesterVatId = null): VatIdCheck
    {
        return new VatIdCheck($this->validator->validate($vatId));
    }
}
