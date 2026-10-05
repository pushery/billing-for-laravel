<?php

declare(strict_types=1);

namespace Pushery\Billing\Consumer;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Whether a calendar day is a public holiday somewhere in Germany.
 *
 * ## Every state's, because the package cannot know which state applies
 *
 * Public holidays are state law, and the nationwide ones are the smaller part. A rule that moves a deadline off a
 * holiday asks about the holiday at a place, and the only place the package could name is the application's, which
 * is not the buyer's. Reading the union errs towards the buyer: a deadline moves for the holidays of every state,
 * so no buyer loses a day their own state gives them.
 *
 * ## The calendar in force since 2023
 *
 * The newest of these days, International Women's Day in Mecklenburg-Western Pomerania, became a holiday in 2023.
 * A holiday a state proclaims for a single year, such as Berlin's on 8 May 2025, is not in it. Augsburg's Peace
 * Festival on 8 August is: it is a statutory holiday in that city.
 *
 * The day is read as the calendar day of the moment passed in, in that moment's time zone.
 */
final readonly class GermanPublicHolidays
{
    /**
     * The holidays on a fixed date, as month and day.
     *
     * New Year, Epiphany, International Women's Day, Labor Day, the Augsburg Peace Festival, the Assumption, World
     * Children's Day, German Unity Day, Reformation Day, All Saints' Day and both days of Christmas.
     */
    private const array FIXED = ['01-01', '01-06', '03-08', '05-01', '08-08', '08-15', '09-20', '10-03', '10-31', '11-01', '12-25', '12-26'];

    /**
     * The holidays that move with Easter, as days after Easter Sunday.
     *
     * Good Friday, Easter Sunday and Monday, Ascension, Whit Sunday and Monday, and Corpus Christi. The two Sundays
     * are holidays in their own right in some states and would move a deadline as Sundays anyway.
     */
    private const array FROM_EASTER = [-2, 0, 1, 39, 49, 50, 60];

    public static function includes(CarbonInterface $day): bool
    {
        if (in_array($day->format('m-d'), self::FIXED, true)) {
            return true;
        }

        // Every holiday that moves with Easter falls between late March and late June, so the distance is never
        // counted across a new year.
        if (in_array($day->dayOfYear - self::easterSunday($day->year)->dayOfYear, self::FROM_EASTER, true)) {
            return true;
        }

        return $day->dayOfYear === self::dayOfRepentanceAndPrayer($day->year)->dayOfYear;
    }

    /**
     * Easter Sunday in the Gregorian calendar, by the anonymous algorithm also known as Meeus/Jones/Butcher.
     *
     * Computed rather than read from `easter_date()`, which needs the calendar extension and counts in the server's
     * time zone.
     */
    private static function easterSunday(int $year): CarbonImmutable
    {
        $golden = $year % 19;
        $century = intdiv($year, 100);
        $yearOfCentury = $year % 100;
        $epact = (19 * $golden + $century - intdiv($century, 4) - intdiv($century - intdiv($century + 8, 25) + 1, 3) + 15) % 30;
        $weekday = (32 + 2 * ($century % 4) + 2 * intdiv($yearOfCentury, 4) - $epact - $yearOfCentury % 4) % 7;
        $correction = intdiv($golden + 11 * $epact + 22 * $weekday, 451);
        $marchDays = $epact + $weekday - 7 * $correction + 114;

        return CarbonImmutable::parse(sprintf('%d-%02d-%02d', $year, intdiv($marchDays, 31), $marchDays % 31 + 1), 'UTC');
    }

    /** The Wednesday before 23 November: Saxony's Day of Repentance and Prayer. */
    private static function dayOfRepentanceAndPrayer(int $year): CarbonImmutable
    {
        $november22 = CarbonImmutable::parse($year.'-11-22', 'UTC');

        return $november22->subDays(($november22->dayOfWeek + 4) % 7);
    }
}
