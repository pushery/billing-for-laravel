<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;

/**
 * A configured retention window is SHORTER than the law requires, and nobody said to allow that.
 *
 * The right to erasure yields to a legal retention obligation (GDPR Art. 17(3)(b)); in Germany an invoice
 * must be kept for ~10 years (§147 AO, §14b UStG). Deleting a financial record before that window closes is
 * a bookkeeping violation, so the package refuses to boot on a window below the statutory floor rather than
 * quietly prune tax records too early.
 *
 * The floor is a FLOOR, not a target: keeping data LONGER is always allowed. Only shortening it below the
 * legal minimum is refused, and only until an operator opts in on purpose (billing.retention.
 * allow_below_statutory_minimum) — for a jurisdiction whose minimum genuinely is shorter than the German
 * default this ships with.
 */
final class RetentionBelowStatutoryMinimum extends RuntimeException
{
    public static function forFinancialRecords(int $configuredDays, int $floorDays): self
    {
        return new self(
            "billing.retention.erased_financial_days is {$configuredDays} days, below the ~10-year statutory ".
            "floor of {$floorDays} days for financial records (Germany: §147 AO, §14b UStG). Deleting an ".
            'invoice before then is a bookkeeping violation. Keep it longer, or — only if your jurisdiction '.
            'genuinely allows less — set billing.retention.allow_below_statutory_minimum to true on purpose.'
        );
    }
}
