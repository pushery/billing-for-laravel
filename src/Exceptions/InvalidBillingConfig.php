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
 *
 * Concatenation in this class assembles sentence text rather than behavior, so swapping or dropping
 * a fragment measures where the line was wrapped, not what a test asserts. The values themselves are
 * held by a dedicated guard that varies every parameter individually.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class InvalidBillingConfig extends RuntimeException
{
    /**
     * A configured value that cannot be read as what it must be.
     *
     * Refused rather than coerced, because the coerced form of a broken money setting is almost always a
     * plausible one — a rate that arrived as a string becomes zero, and a zero commission is
     * indistinguishable from a platform that deliberately takes nothing.
     */
    public static function forKey(string $key, string $requirement): self
    {
        return new self("Configuration key [{$key}] {$requirement}.");
    }

    /** @param  list<string>  $supported */
    public static function unsupportedMerchantAccountType(string $type, array $supported): self
    {
        return new self(
            "billing.marketplace.onboarding.account_type is '{$type}', which this driver does not support ".
            '(supported: '.implode(', ', $supported).'). The account type decides who carries the identity '.
            'checks and who absorbs a loss, and it cannot be changed once a merchant has onboarded — so it '.
            'is refused at boot rather than defaulted silently.'
        );
    }

    public static function implausibleFoundingYear(int $year, int $earliest, int $latest): self
    {
        return new self(
            "The declared business founding year {$year} is outside the plausible range {$earliest}–{$latest}. "
            .'This is refused rather than rounded because a threshold reads it: an early founding year and a '
            .'late one put a business under different rules, so a wrong year changes the answer instead of '
            .'blurring it. It is also never derived from when the merchant signed up here — those are '
            .'different facts, and they differ routinely.'
        );
    }

    public static function unreadableHoldEnforcementDate(string $configured): self
    {
        return new self(
            "billing.marketplace.tax_status_hold.enforce_from is set to \"{$configured}\", which is not a "
            .'date. It is refused rather than interpreted, because both ways of guessing are worse than '
            .'stopping: reading it as "now" would refuse every routed sale on a typo, and reading it as '
            .'"unset" would switch a tax control off without saying so. Set a date the hold should begin on, '
            .'or leave the key empty to say it has not begun.'
        );
    }

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
