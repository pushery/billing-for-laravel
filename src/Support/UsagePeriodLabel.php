<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Carbon\CarbonImmutable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Translation\Translator;

/**
 * A usage period's key the way a customer reads it.
 *
 * The counters key a period by the day it began (`2026-09-06`) while a subscription carries period bounds, and by
 * the calendar month (`2026-09`) otherwise. The first reads as "Period from" its localized start date, because
 * the key alone does not say where the period ended; the second as the month and year in the app's locale. A
 * key of any other shape is shown as it is.
 */
final class UsagePeriodLabel
{
    public static function for(string $key): string
    {
        $translator = Container::getInstance()->make(Translator::class);
        $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $key) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $key, 'UTC') : null;

        if ($day instanceof CarbonImmutable) {
            $label = $translator->get('billing::account.usage_history.period_from', ['date' => (string) LocalizedDate::short($day)]);

            return is_string($label) ? $label : $key;
        }

        $month = preg_match('/^\d{4}-\d{2}$/', $key) === 1 ? CarbonImmutable::createFromFormat('!Y-m', $key, 'UTC') : null;

        return $month instanceof CarbonImmutable ? $month->settings(['locale' => $translator->getLocale()])->isoFormat('MMMM YYYY') : $key;
    }
}
