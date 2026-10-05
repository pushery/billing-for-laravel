<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

/**
 * A payment method the provider holds, as PaymentRails::tokenize() describes it: the driver token, optional
 * display hints, and whether it can be charged off-session.
 */
final readonly class TokenizedMethod
{
    public function __construct(
        public string $token,
        public bool $offSessionCapable = false,
        public ?string $brand = null,
        public ?string $last4 = null,
    ) {}
}
