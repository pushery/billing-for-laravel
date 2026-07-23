<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

/**
 * A reference to a stored payment mandate/token that can be charged later (a stored mandate, a Stripe payment method, or another recurring detail). The `reusable` flag records whether it may be
 * used for off-session (merchant-initiated) charges. `customerReference` carries the provider
 * customer the mandate is attached to — required by some providers (Stripe) to charge a stored
 * method off-session — and is null for providers/mandates that do not need it.
 */
final readonly class MandateReference
{
    public function __construct(
        public string $id,
        public string $method,
        public bool $reusable = true,
        public ?string $customerReference = null,
    ) {}
}
