<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;

/**
 * A subscription line cannot be priced for its cycle.
 *
 * It is an exception rather than a zero for the reason zero is dangerous here: a metered line with no usage
 * legitimately costs nothing, so returning zero for "I could not work this out" produces an invoice that
 * looks complete and settled while silently billing nothing. The failure has to be loud, because the
 * successful-looking version of it is indistinguishable from correct behavior.
 */
final class CycleAmountUnresolvable extends RuntimeException
{
    public static function fixedLineHasNoAmount(string $planKey): self
    {
        return new self(
            "The subscription line '{$planKey}' is not metered but carries no amount, so there is nothing ".
            'to bill for this cycle. A fixed line must be written with the amount it costs; only a metered '.
            'line may be stored unpriced, and only until its resolver prices it.'
        );
    }

    public static function noResolverForMeteredLine(string $planKey): self
    {
        return new self(
            "The subscription line '{$planKey}' is metered, so its amount can only come from the usage it ".
            'accrued — but no resolver was named for it. Either bind a CycleAmountResolver that can price '.
            "usage, or name one on the line's `preprocessor` column."
        );
    }

    public static function meterNotInCatalog(string $meterKey, ?string $tierKey): self
    {
        $tier = $tierKey ?? '(none)';

        return new self(
            "The metered line '{$meterKey}' has no matching component in the catalog for tier '{$tier}', so ".
            'there is no price to rate its usage with. Add the component to the tier, or stop billing the '.
            'line. It is refused rather than rated at zero, which would hand the usage over for free.'
        );
    }

    public static function componentHasNoUnitPrice(string $meterKey): self
    {
        return new self(
            "The metered component '{$meterKey}' carries no unit price, so its usage cannot be rated ".
            'locally. A driver that rates usage remotely does not need one; a local billing engine does, '.
            'and billing zero instead would give the usage away silently.'
        );
    }
}
