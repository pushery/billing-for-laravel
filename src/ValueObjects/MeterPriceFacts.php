<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

/**
 * What the PROVIDER'S price actually says about a metered component — the authority the local config has to
 * agree with.
 *
 * This matters more than it looks. The free allowance does not live in `billing.tiers.*.metered.*.included`
 * — that value only drives the gauge the customer sees. It lives in the provider's PRICE (a graduated first
 * tier costing nothing up to the allowance). If the two disagree, nothing breaks visibly: the customer is
 * simply given a different number of free units than the interface promised them, and nobody finds out until
 * an invoice looks wrong.
 *
 * A null field means the provider did not say — the price is not meter-backed, or not tiered.
 */
final readonly class MeterPriceFacts
{
    public function __construct(
        /** The event name of the meter this price is backed by, or null when it is not metered at all. */
        public ?string $meterEventName = null,
        /** The price's currency, uppercased. */
        public ?string $currency = null,
        /** The graduated first tier's `up_to` — the units the provider actually gives away free. */
        public ?int $firstTierUpTo = null,
    ) {}
}
