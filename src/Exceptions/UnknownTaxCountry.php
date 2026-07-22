<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;

/**
 * The country code handed to a local tax calculator is not a country.
 *
 * This exists because "no rate for this code" has two very different causes that look identical once the
 * answer is a number: a supply outside the EU VAT area is legitimately zero-rated, while a broken code
 * ("DEU", "Germany", an empty string, a code that was never assigned) is a data defect. Returning 0% for
 * both makes the second one invisible — the invoice goes out short, and nothing surfaces it until the VAT
 * return does not add up. So a code that is not a country is refused instead of silently zero-rated.
 *
 * Note what this can and cannot catch: a code that is malformed or unassigned is caught here, but a typo
 * that happens to land on ANOTHER assigned country ("DE" mistyped as "DK") is indistinguishable from a
 * deliberate supply to that country and is not detectable at this layer.
 */
final class UnknownTaxCountry extends RuntimeException
{
    public static function code(string $country): self
    {
        return new self(
            "The tax country code '".self::readable($country)."' is not an assigned ISO 3166-1 alpha-2 ".
            'country code, so no VAT treatment can be determined for it. It is refused rather than '.
            'zero-rated, because a zero here is indistinguishable from a legitimate supply outside the EU '.
            'VAT area and would under-declare VAT silently. Pass a two-letter ISO 3166-1 country code '.
            '(e.g. "DE", "US").'
        );
    }

    /**
     * Render an untrusted value for inclusion in the message.
     *
     * The value reaching this class comes from request data, and the message does not stay in memory: the
     * package persists exception messages into failure-reason columns and logs them. So it is stripped of
     * control characters (which would forge line structure in a log) and bounded in length before it is
     * embedded, rather than passed through because "it is only an error message".
     */
    private static function readable(string $value): string
    {
        $clean = preg_replace('/[^\P{C}]++/u', '', $value) ?? '';

        if (trim($clean) === '') {
            return $value === '' ? '(empty)' : '(blank)';
        }

        return mb_strlen($clean) > 32 ? mb_substr($clean, 0, 32).'…' : $clean;
    }
}
