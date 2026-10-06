<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Support\Facades\Lang;
use NumberFormatter;
use Pushery\Billing\ValueObjects\Money;

/**
 * An amount as a reader of a locale writes it, for text a person reads.
 *
 * `Money::format()` writes `29.00 EUR` in every language, which is the form a log or a payload wants and not the
 * one a sentence does: a German reader writes `29,00 €`, an English one `€29.00`.
 *
 * Without a locale the app's is read on every call, for the reason `LocalizedDate` gives: nothing in a framework
 * process guarantees that a library follows `App::setLocale()` on its own. A document passes the language it is
 * written in.
 *
 * With the intl extension the amount is formatted by ICU for the locale and the currency. Without it the
 * amount keeps its currency code and takes the locale's decimal mark, which every reader parses: `29,00 EUR` in
 * German, `29.00 EUR` in English. The package does not require intl, so this is the form an install without it
 * gets rather than a failure.
 */
final class LocalizedMoney
{
    public static function format(Money $money, ?string $locale = null): string
    {
        return self::render($money, $locale ?? Lang::getLocale(), extension_loaded('intl'));
    }

    /**
     * The amount in the given locale, through ICU or without it.
     *
     * Public so that both forms can be shown on one machine, which has intl or does not.
     */
    public static function render(Money $money, string $locale, bool $intl): string
    {
        if ($intl) {
            return new NumberFormatter($locale, NumberFormatter::CURRENCY)
                ->formatCurrency((float) $money->toDecimal(), $money->currency);
        }

        return str_replace('.', LocalizedNumber::decimalMark($locale), $money->toDecimal()).' '.$money->currency;
    }
}
