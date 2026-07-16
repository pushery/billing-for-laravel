<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;

/**
 * The billing configuration is internally inconsistent — a misconfiguration that would otherwise fail
 * SILENTLY at runtime: a zero_tier that grants free access to a tier that does not exist, a tier pointing at
 * an unknown dimension, dunning rungs out of order that never escalate. Like the webhook-secret and metering
 * guards, this refuses to boot with a clear message rather than let the dashboard break or a customer be
 * mis-tiered mid-request.
 */
final class InvalidBillingConfig extends RuntimeException
{
    public static function ownerMode(string $owner): self
    {
        return new self("billing.owner must be 'user' or 'team', got '{$owner}'.");
    }

    public static function zeroTierMissing(string $zeroTier): self
    {
        return new self("billing.zero_tier is '{$zeroTier}', but no tier with that key is defined in billing.tiers.");
    }

    public static function untouchableTierMissing(string $tier): self
    {
        return new self("billing.untouchable_tiers lists '{$tier}', but no tier with that key is defined in billing.tiers.");
    }

    public static function unknownDimension(string $tier, string $dimension): self
    {
        return new self("Tier '{$tier}' references dimension '{$dimension}', which is not defined in billing.dimensions.");
    }

    public static function invalidCurrency(string $where, string $currency): self
    {
        return new self("The price currency '{$currency}' at {$where} is not a valid ISO 4217 code (three uppercase letters).");
    }

    public static function warnThresholdOutOfRange(string $dimension, float $value): self
    {
        return new self("Dimension '{$dimension}' warn_threshold must be between 0 and 1, got {$value}.");
    }

    public static function dunningNotAscending(int $after, int $previous): self
    {
        return new self("The dunning ladder's after_days must strictly ascend; {$after} does not follow {$previous}.");
    }
}
