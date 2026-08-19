<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\App;

/**
 * The one place this package turns a moment into text a person reads.
 *
 * ## Why this exists at all
 *
 * Every view used to write `format('d.m.Y')`. The package ships seven locales, so six of them got a
 * German date — and for `en` that is not merely unidiomatic but ambiguous: `03.09.2026` is the 3rd of
 * September or the 9th of March depending on the reader, and both readings are reasonable. On an
 * INVOICE and on an access-expiry date, guessing wrong is not a cosmetic problem.
 *
 * ## The trap this class exists to close, measured rather than assumed
 *
 * The obvious fix — `->isoFormat('L')` and let Carbon use "the locale" — is WRONG here, and silently
 * so. Laravel does not carry its locale into Carbon. `Application::setLocale()` sets the translator
 * and fires `LocaleUpdated`, and nothing in the framework listens for it: across all of `Illuminate/`
 * the event appears in exactly two files, the Application that fires it and the event class itself.
 * Measured by switching the app locale five times and reading both sides:
 *
 *     App::setLocale('de') -> translator 'de', Carbon 'en', isoFormat('L') = 09/03/2026
 *     App::setLocale('fr') -> translator 'fr', Carbon 'en', isoFormat('L') = 09/03/2026
 *
 * So a bare `isoFormat()` would have rendered American dates for all seven languages: the same bug,
 * pointing the other way, and harder to notice — the test somebody writes first runs under `en`,
 * where it looks perfect.
 *
 * Hence: the locale is read from the app and handed to Carbon EXPLICITLY, on every call.
 *
 * ## Why there are three methods and not one
 *
 * `short()` is the direct replacement for what the views did: numeric, compact, right inside a table
 * cell. `long()` spells the month out, and exists for ONE reason — a document that outlives the
 * session it was rendered in. `03/09/2026` is what `es`, `fr`, `it` and `pt` produce, and it is
 * character-for-character what `en` produces for a DIFFERENT day; an invoice read in another country
 * years later has no locale to disambiguate it, and the month name needs none.
 *
 * `shortWithTime()` is `short()` plus the clock, for the admin event log — the only place carrying a
 * time. Without it, converting that column would have dropped the time silently, which is exactly the
 * kind of loss a sweeping replacement makes and nobody notices.
 *
 * ## What this deliberately does NOT do
 *
 * It does not convert time zones. The values reaching it are already whatever the caller stored (this
 * package stores UTC through its own cast), and shifting them here would change WHICH MOMENT is shown
 * while pretending to change only how it is spelled. That is a separate decision with its own test.
 */
final class LocalizedDate
{
    /** Numeric and compact: `03.09.2026` in German, `09/03/2026` in English. Null passes through. */
    public static function short(?DateTimeInterface $moment): ?string
    {
        return self::render($moment, 'L');
    }

    /** The month spelled out: `3. September 2026`, `September 3, 2026`. For documents, not for tables. */
    public static function long(?DateTimeInterface $moment): ?string
    {
        return self::render($moment, 'LL');
    }

    /** Compact date plus the clock, in the locale's own convention — `14:05` in German, `2:05 PM` in English. */
    public static function shortWithTime(?DateTimeInterface $moment): ?string
    {
        return self::render($moment, 'L LT');
    }

    /**
     * Null in, null out — so a caller keeps its own `?? '—'` and no view has to learn a second way of
     * saying "there is no date here".
     */
    private static function render(?DateTimeInterface $moment, string $format): ?string
    {
        if (! $moment instanceof DateTimeInterface) {
            return null;
        }

        // Per call, never Carbon's global: the global is the one Laravel fails to set, and a process-wide
        // setLocale would leak this package's choice into the host application's own dates.
        //
        // `settings()` rather than the more obvious `locale()`: the latter is overloaded — with no
        // argument it RETURNS the current locale as a string, so its signature is `static|string` and a
        // chained `->isoFormat()` does not type-check. Narrowing that with an `instanceof` would add a
        // branch no run can enter, which the coverage floor would then report as an untested line
        // forever. `settings()` returns `static` unconditionally and produces the identical output,
        // verified across all seven shipped locales.
        return CarbonImmutable::instance($moment)
            ->settings(['locale' => App::getLocale()])
            ->isoFormat($format);
    }
}
