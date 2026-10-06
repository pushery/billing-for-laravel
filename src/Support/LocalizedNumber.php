<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Support\Facades\Lang;
use NumberFormatter;

/**
 * A number as a reader of a locale writes it, for text a person reads.
 *
 * `number_format()` writes English unless it is handed separators, and then it writes the language those
 * separators belong to: a German reader takes `1,500` for one and a half. This asks the locale instead, so the
 * same number is `1.500` in German and `1,500` in English, and a rate is `7,5 %` and `7.5%`.
 *
 * Without a locale the app's is read on every call, for the reason `LocalizedDate` gives. A document passes the
 * language it is written in.
 *
 * With the intl extension the number is formatted by ICU. Without it the number takes the language's decimal mark
 * and no grouping, which every reader parses: `1500` and `2,5` in German. The package does not require intl, so
 * this is the form an install without it gets rather than a failure.
 */
final class LocalizedNumber
{
    /** A number with a fixed count of decimals. */
    public static function format(int|float $number, int $decimals = 0, ?string $locale = null): string
    {
        return self::render($number, $decimals, $locale ?? Lang::getLocale(), extension_loaded('intl'));
    }

    /** A rate given in percent, with at most two decimals. */
    public static function percent(int|float $percent, ?string $locale = null): string
    {
        return self::renderPercent($percent, $locale ?? Lang::getLocale(), extension_loaded('intl'));
    }

    /**
     * The number in the given locale, through ICU or without it.
     *
     * Public so that both forms can be shown on one machine, which has intl or does not.
     */
    public static function render(int|float $number, int $decimals, string $locale, bool $intl): string
    {
        if ($intl) {
            $formatter = new NumberFormatter($locale, NumberFormatter::DECIMAL);
            $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $decimals);
            $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $decimals);

            return (string) $formatter->format($number);
        }

        return number_format($number, $decimals, self::decimalMark($locale), '');
    }

    /** The rate in the given locale, through ICU or without it, public for the reason render() gives. */
    public static function renderPercent(int|float $percent, string $locale, bool $intl): string
    {
        if ($intl) {
            $formatter = new NumberFormatter($locale, NumberFormatter::PERCENT);
            $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, 2);

            return (string) $formatter->format($percent / 100);
        }

        $number = rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.');

        return str_replace('.', self::decimalMark($locale), $number).'%';
    }

    /** The decimal mark of a locale's language: a point in English, a comma in every other language the package ships. */
    public static function decimalMark(string $locale): string
    {
        $language = strtolower(strtok(str_replace('-', '_', $locale), '_') ?: $locale);

        return $language === 'en' ? '.' : ',';
    }
}
