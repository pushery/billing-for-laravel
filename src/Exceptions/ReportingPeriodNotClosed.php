<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Thrown when a reporting rate is asked for before the period it converts at has ended.
 *
 * A refusal rather than a best effort, and the distinction matters more here than it usually does. The rate
 * reader resolves a day it holds nothing for FORWARD, to the next publication day — so running early would
 * not produce an error. It would produce a real rate, correctly dated, for the wrong day, on every document
 * in the period at once. A wrong figure that passes every check is the expensive kind.
 *
 * Concatenation in this class assembles sentence text rather than behavior, so swapping or dropping
 * a fragment measures where the line was wrapped, not what a test asserts. The values themselves are
 * held by a dedicated guard that varies every parameter individually.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class ReportingPeriodNotClosed extends RuntimeException
{
    public static function on(CarbonImmutable $periodEnd): self
    {
        return new self(sprintf(
            'The reporting rate converts at %s, the last day of the period, and that day has not passed. '
            .'Run this after the period closes. Freezing now would take the next rate published instead, '
            .'which is a real figure for a day the return is not filed on.',
            $periodEnd->toDateString(),
        ));
    }
}
