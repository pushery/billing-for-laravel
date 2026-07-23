<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Pushery\Billing\Enums\BillingInterval;

/**
 * A billable plan as the package defines it: a price + interval owned locally, with an OPTIONAL
 * mapping to a provider price. This is the "never assume plan == Stripe price id" guarantee — a
 * plan is a first-class local concept; the Stripe driver may map it to a remote price, while the
 * the local engine bills the local amount directly.
 */
final readonly class Plan
{
    /** @param array<string,string> $metadata */
    public function __construct(
        public string $key,
        public Money $amount,
        public BillingInterval $interval,
        public ?string $providerPriceId = null,
        public array $metadata = [],
    ) {}

    /** Whether this plan is mapped to a remote provider price (e.g. a Stripe price id). */
    public function isProviderMapped(): bool
    {
        return $this->providerPriceId !== null;
    }
}
