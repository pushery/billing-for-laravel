<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * A mail line that carries text a person typed, reading exactly as typed in both the HTML and the text version of
 * the mail, and unable to become a link or markup in either.
 *
 * A `MailMessage` line is Markdown, and escaping HTML does not help there: `[Open your account](https://…)` holds no
 * HTML and renders as a working link, in a message the business sent to an address the sender did not have to own.
 * A backslash in front of the punctuation does not help either. Blade escapes a line again before the Markdown
 * renderer reads it, so a backslash ends up in front of an entity instead of a character, and the text version
 * keeps every backslash, so a typed domain reads `shop\.example\.com`.
 *
 * So each ASCII punctuation character of a value becomes a numeric character reference. CommonMark resolves a
 * reference to its character and never reads it as syntax, and the text version decodes it. The line comes back as
 * an `HtmlString`, which both versions print as it is. A line break in a value is folded into one space first, as
 * a plain line would be, because a line break is where a new Markdown block starts.
 *
 * The line itself is trusted text, typically a translation: it is HTML-escaped, and its own Markdown still works.
 */
final class MailText
{
    /** The ASCII punctuation CommonMark lets a backslash precede, which is every character it can read as syntax. */
    private const string PUNCTUATION = '!"#$%&\'()*+,-./:;<=>?@[\\]^_`{|}~';

    /**
     * The line with its placeholders filled in, in the three spellings the translator knows (`:name`, `:Name` and
     * `:NAME`), the longest first, in one pass.
     *
     * @param  array<string, string>  $values
     */
    public static function line(string $line, array $values): HtmlString
    {
        $replacements = [];

        foreach ($values as $key => $value) {
            $value = preg_replace('/\s*(?:\r\n|\r|\n)\s*/', ' ', $value) ?? $value;

            $replacements[':'.Str::ucfirst($key)] = self::references(Str::ucfirst($value));
            $replacements[':'.Str::upper($key)] = self::references(Str::upper($value));
            $replacements[':'.$key] = self::references($value);
        }

        return new HtmlString(strtr(trim(e($line)), $replacements));
    }

    /** Every ASCII punctuation character of a value as a numeric character reference. */
    private static function references(string $value): string
    {
        $characters = str_split(self::PUNCTUATION);

        return strtr($value, array_combine(
            $characters,
            array_map(static fn (string $character): string => '&#'.ord($character).';', $characters),
        ));
    }
}
