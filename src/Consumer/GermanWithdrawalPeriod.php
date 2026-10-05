<?php

declare(strict_types=1);

namespace Pushery\Billing\Consumer;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * When a consumer's fourteen days to withdraw run out under German law.
 *
 * ## Counted in days, so the last one counts to its end
 *
 * The day the work was provided does not count (§ 187 Abs. 1 BGB), and the period ends when the fourteenth day after
 * it has passed (§ 188 Abs. 1 BGB). A work provided on 1 September at 15:00 can be withdrawn from until 15 September
 * has ended, not until 15:00 on it.
 *
 * ## On the German calendar, whatever the application's time zone
 *
 * The day the work was provided on, and the moment a day ends, are read in Europe/Berlin. An application that runs in
 * UTC would otherwise end the window at 01:00 or 02:00 German time on the following day. The moment returned is the
 * same instant in the time zone of the moment passed in, so it is stored the way that moment is.
 *
 * ## A last day that is not a working day moves to the next one
 *
 * A withdrawal is a declaration made within a period, so when the period's last day is a Saturday, a Sunday or a
 * public holiday, the next working day takes its place (§ 193 BGB). The holiday is one at the place the declaration
 * is made, which the package does not know, so the holidays of every state count ({@see GermanPublicHolidays}). A
 * buyer in a state without that holiday gains a day, where one in a state with it would otherwise lose one.
 */
final readonly class GermanWithdrawalPeriod
{
    private const string ZONE = 'Europe/Berlin';

    private const int DAYS = 14;

    /**
     * The last moment a withdrawal is in time for a work provided at `$providedAt`.
     *
     * @param  CarbonInterface  $providedAt  when the work was provided, in any time zone
     */
    public static function endsAfter(CarbonInterface $providedAt): CarbonImmutable
    {
        $lastDay = CarbonImmutable::instance($providedAt)->setTimezone(self::ZONE)->startOfDay()->addDays(self::DAYS);

        while ($lastDay->isSaturday() || $lastDay->isSunday() || GermanPublicHolidays::includes($lastDay)) {
            $lastDay = $lastDay->addDay();
        }

        return $lastDay->endOfDay()->setTimezone($providedAt->getTimezone());
    }
}
