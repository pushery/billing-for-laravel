<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Pushery\Billing\Contracts\SmallBusinessExemptionValidator;
use Pushery\Billing\Contracts\SmallBusinessIdValidator;
use Pushery\Billing\Enums\VatIdValidation;

/**
 * The shipped default: asks the bound {@see SmallBusinessIdValidator} and leaves the member state out.
 *
 * An implementation of the one-argument contract predates the member state, and it has to keep answering
 * when a caller starts passing one. Out of the box the one-argument contract is bound to the null validator,
 * so nothing is contacted and the answer is `Unavailable`. The member state reaches a register only when you
 * bind an implementation of this contract that sends it, {@see SmeOnTheWebExemptionValidator}.
 */
final readonly class DelegatingSmallBusinessExemptionValidator implements SmallBusinessExemptionValidator
{
    public function __construct(private SmallBusinessIdValidator $validator) {}

    public function validate(?string $registrationId, string $memberState): VatIdValidation
    {
        unset($memberState);

        return $this->validator->validate($registrationId);
    }
}
