<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * What a retention period is counted FROM.
 *
 * The anchor decides as much as the length does, and getting it wrong is invisible: a period counted from
 * the wrong moment still produces a plausible date, just an earlier or later one than the obligation
 * allows. A document issued in March and one issued that December are kept the same length when the clock
 * starts at the year's end, and nine months apart when it starts at the instant — one of those is what the
 * obligation says, and nothing about the other looks wrong.
 */
enum RetentionClock: string
{
    /** There is no clock: the record goes when the person it belongs to is erased. */
    case SubjectErasure = 'subject_erasure';

    /** Counted from when the row was written. */
    case CreatedAt = 'created_at';

    /**
     * Counted from the END of the year the document was issued in — so everything issued in one year ages
     * out together, rather than each document keeping its own anniversary.
     */
    case IssueYearEnd = 'issue_year_end';

    /** Counted from the close of the period a report covers. */
    case ReportingPeriodEnd = 'reporting_period_end';

    /**
     * No period at all: there is no reason to hold this, so it must already be gone.
     *
     * A retention of zero is not a retention, it is an active duty to discard — and a duty nothing enforces
     * is a sentence in a policy document. Its enforcement is at the point of processing; what the retention
     * run does with it is REPORT a survivor as a defect, because a survivor means some code path did not
     * discard when it should have.
     */
    case Immediate = 'immediate';
}
