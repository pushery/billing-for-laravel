<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

/**
 * A freshly tokenised payment method returned by PaymentRails::tokenize(). Carries the driver token
 * plus optional display hints and whether it can be charged off-session.
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
