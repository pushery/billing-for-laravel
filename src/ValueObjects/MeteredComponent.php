<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Pushery\Billing\Enums\MeteringPolicy;

/**
 * One usage-billed component of a tier: what is counted, what a package of it costs, and how much of
 * it the tier already includes. This is the thing that turns "12 000 emails sent" into money.
 *
 * WHO OWNS THE ARITHMETIC. The provider rates the usage, not this package. The allowance and the
 * packaging live in the PROVIDER'S price (a graduated tier priced at 0 up to `included`, then a
 * package of `packageSize`), and raw units are reported to it. The values here exist to render the
 * gauge, to let a local billing engine rate the same usage itself, and to be checked against the
 * provider's price so the two cannot silently disagree. Netting the allowance locally AND letting the
 * provider net it again is how a customer gets twice the free units they paid for.
 */
final readonly class MeteredComponent
{
    public function __construct(
        public string $key,
        public string $label,
        public string $unit,
        /** The provider's meter name — the event stream usage is reported into. */
        public ?string $providerMeter = null,
        /** The provider's metered price — the subscription item usage is billed on. */
        public ?string $providerPrice = null,
        /** Units per billed package (1000 → priced per 1 000 emails). */
        public int $packageSize = 1,
        /** What one package costs. */
        public ?Money $unitPrice = null,
        /** Units included in the tier before anything is billed; null means nothing is free. */
        public ?int $included = null,
        public MeteringPolicy $policy = MeteringPolicy::FairUse,
        /**
         * The fraction of the included allowance at which the customer is warned they are running out —
         * the gauge turns amber here, and it is where the quota-warning notice fires. 0.8 unless the meter
         * configures its own: a meter whose overage is expensive wants warning earlier than one that is
         * merely informational.
         */
        public float $warnThreshold = 0.8,
    ) {}

    /** Whether this component can actually be billed at a provider. */
    public function isBillable(): bool
    {
        return $this->providerMeter !== null && $this->providerPrice !== null;
    }

    /** Units past the included allowance — what the provider's price actually rates. */
    public function billableUnits(int $used): int
    {
        return max(0, $used - ($this->included ?? 0));
    }

    /** Whole packages the billable units fall into; a started package is a charged package. */
    public function packages(int $used): int
    {
        return (int) ceil($this->billableUnits($used) / max(1, $this->packageSize));
    }

    /**
     * What the usage costs so far, for display. Null when the component carries no price — the screen
     * then says nothing rather than showing a figure the invoice will contradict.
     */
    public function costOf(int $used): ?Money
    {
        if (! $this->unitPrice instanceof Money) {
            return null;
        }

        return Money::of($this->unitPrice->minorUnits * $this->packages($used), $this->unitPrice->currency);
    }
}
