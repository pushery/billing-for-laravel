<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\ValueObjects\MeteredDimension;
use Pushery\Billing\ValueObjects\Money;

/**
 * The entitlement view of a tier, implemented by the project's own tier type (e.g. a Plan enum). It
 * exposes what the account hub needs to describe and compare a tier without knowing the project's
 * domain.
 */
interface Entitlements
{
    public function tierKey(): string;

    public function label(): string;

    public function priceDisplay(): ?Money;

    public function isByok(): bool;

    /** @return list<MeteredDimension> the tier's metered dimensions. */
    public function dimensions(): array;

    /** Whether this entitlement ranks above another (drives upgrade/downgrade affordances). */
    public function isUpgradeOver(self $other): bool;
}
