<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use Pushery\Billing\Models\ReportingFiling;
use RuntimeException;

/**
 * A filing was refused, and every refusal here is in the same direction: reporting a period more than once.
 *
 * ## Why over-reporting is the direction that gets refused
 *
 * Under-reporting is visible. A period nobody filed is a gap an authority names, an operator answers, and a
 * calendar warns about beforehand. Over-reporting is not: two filings of the same year both look like a
 * filing, both look complete, and the duplicate is discovered by the party being reported on — which is the
 * expensive way to find out that a seller's figures were transmitted twice.
 *
 * So a second first filing is an error rather than an idempotent no-op. A no-op would be the friendlier
 * answer and the wrong one: it would silently accept the call that meant to file a correction and forgot
 * to say so, and nothing downstream would ever say which of the two it had been.
 */
final class ReportingFilingRefused extends RuntimeException
{
    /** The period already went out once, and a second first filing is not how a filed period changes. */
    public static function periodAlreadyFiled(int $year, string $currency, ReportingFiling $existing): self
    {
        return new self(
            "The {$year} {$currency} reporting period was already filed on "
            .$existing->filed_at->toDateString()." by {$existing->filed_by}. Filing it again would report "
            .'the same period twice. File a correction naming that filing if the figures have moved.'
        );
    }

    /**
     * There is a correction to file and nothing that it corrects.
     *
     * A period whose first filing never happened is not corrected — it is FILED, and the two are different
     * acts with different sequence numbers. Producing a correction with no predecessor would put a record
     * into the chain that claims to amend something nobody ever sent.
     */
    public static function nothingToCorrect(int $year, string $currency): self
    {
        return new self(
            "The {$year} {$currency} reporting period has never been filed, so there is nothing to correct. "
            .'File it first — a correction names the filing it amends, and this period has none.'
        );
    }

    /** A correction of one period cannot carry another period's figures. */
    public static function correctsAnotherPeriod(int $year, string $currency, ReportingFiling $corrected): self
    {
        return new self(
            "A {$year} {$currency} record cannot correct the filing of {$corrected->period_year} "
            ."{$corrected->currency}. A correction restates ONE period, and a record that restates a "
            .'different one is a first filing of that period rather than a correction of this one.'
        );
    }

    /**
     * A correction has to answer the LATEST filing of its period, never one that is already superseded.
     *
     * Two corrections both naming the original would each claim to be the current state of the period, and
     * whichever arrived second would silently drop the first one's changes — the reader has no way to tell
     * which of two siblings is the later word.
     */
    public static function correctsSupersededFiling(ReportingFiling $named, ReportingFiling $latest): self
    {
        return new self(
            "Filing #{$named->id} (sequence {$named->correction_sequence}) has itself been corrected by "
            ."filing #{$latest->id} (sequence {$latest->correction_sequence}). A correction restates the "
            .'CURRENT state of the period, so it names the latest filing — correcting a superseded one '
            .'would quietly drop everything the corrections in between changed.'
        );
    }

    /** Identical bytes correct nothing, so filing them again is another copy of what already went out. */
    public static function correctionChangesNothing(ReportingFiling $corrected): self
    {
        return new self(
            "This record is byte-for-byte what filing #{$corrected->id} already reported, so there is "
            .'nothing to correct. Filing it would report the period a second time with the same figures, '
            .'which is the over-reporting direction. Produce the period again first: if the bytes still '
            .'match, nothing has moved and no correction is due.'
        );
    }
}
