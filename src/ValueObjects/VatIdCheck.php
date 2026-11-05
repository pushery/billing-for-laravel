<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Carbon\CarbonImmutable;
use Pushery\Billing\Enums\VatIdValidation;

/**
 * What the VAT register answered about a VAT identification number. Each detail is null where the register
 * supplied none: a member state that withholds the name, a number the register does not know, an answer that
 * never came.
 */
final readonly class VatIdCheck
{
    /**
     * @param  string|null  $name  the name the register holds for the number
     * @param  string|null  $address  the address the register holds for the number, as one text
     * @param  CarbonImmutable|null  $checkedAt  the moment the register answered
     * @param  string|null  $reference  the identifier the register gave a qualified request
     * @param  bool  $requesterRefused  whether the register refused the requester of a qualified request; the verdict
     *                                  is Unavailable then, and the same check without a requester answers about the number
     */
    public function __construct(
        public VatIdValidation $verdict,
        public ?string $name = null,
        public ?string $address = null,
        public ?CarbonImmutable $checkedAt = null,
        public ?string $reference = null,
        public bool $requesterRefused = false,
    ) {}
}
