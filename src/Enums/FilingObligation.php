<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * A filing an operator owes, as its own thing.
 *
 * They are separate cases rather than one "filing" with a type field because the whole risk they carry is
 * being treated as one job. Two of them fall due on the same day of the year, and a run that handled "the
 * filings due today" would produce one lock, one acknowledgement, and one line in a report — after which
 * exactly one of the two gets filed and nobody can tell which.
 */
enum FilingObligation: string
{
    /** The periodic return of what was sold where. */
    case PeriodicReturn = 'periodic_return';

    /** The annual report about sellers on the platform. Different law, different data, same calendar day. */
    case AnnualSellerReport = 'annual_seller_report';

    /**
     * The key nothing else may take while this filing is being produced.
     *
     * Separate per obligation, so the one that collides on the calendar cannot block the other out of its
     * own run — the failure mode where a shared lock turns two obligations into one.
     */
    public function lockKey(int $year): string
    {
        return 'billing:filing:'.$this->value.':'.$year;
    }

    /** Where the acknowledgement for a year is recorded. Separate for the same reason the lock is. */
    public function acknowledgementKey(int $year): string
    {
        return 'billing:filing:'.$this->value.':'.$year.':acknowledged';
    }
}
