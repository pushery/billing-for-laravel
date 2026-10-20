<?php

declare(strict_types=1);

namespace Pushery\Billing\Ai;

use Carbon\CarbonImmutable;

/**
 * What one account of the spend brake counted on one UTC day, in the currency it was counted in.
 *
 * `provider` names the provider of a provider's account and is null for an owner's, which counts that owner's calls
 * to every provider together.
 */
final readonly class SpendDay
{
    public function __construct(
        public ?string $provider,
        public CarbonImmutable $day,
        public AiCost $spent,
    ) {}
}
