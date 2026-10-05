<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;

/**
 * Thrown when a return in the reporting currency meets a sale in another currency that carries no reporting rate.
 *
 * Concatenation in this class assembles sentence text rather than behavior, so swapping or dropping a fragment
 * measures where the line was wrapped, not what a test asserts. The values themselves are held by a dedicated guard
 * that varies every parameter individually.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class ReportingRateMissing extends RuntimeException
{
    public static function on(string $document, string $currency, string $reporting): self
    {
        return new self(sprintf(
            'Document %s is in %s and carries no reporting rate into %s, so its sale cannot be declared in the '
            .'return. Run billing:exchange-rates:freeze-reporting for the period it was issued in, then export '
            .'again. Leaving it out would file a return without a sale that was made.',
            $document,
            $currency,
            $reporting,
        ));
    }

    /** The same refusal for the recapitulative statement, which converts under a rate of its own. */
    public static function inStatement(string $document, string $currency, string $reporting): self
    {
        return new self(sprintf(
            'Document %s is in %s and carries no recapitulative statement rate into %s, so its sale cannot be '
            .'stated. Run billing:exchange-rates:freeze-statement for the period it was issued in, then export '
            .'again. Leaving it out would file a statement without a sale that was made.',
            $document,
            $currency,
            $reporting,
        ));
    }
}
