<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Database\QueryException;
use Throwable;

/**
 * What a failure is called in a table the package keeps, without the data the failure carried.
 *
 * A failed webhook effect is recorded so that an operator can find it and replay it, and that table holds
 * nobody: a provider, a reference and an effect class. An exception's own message does not fit in it. A
 * database exception writes the statement's bindings into its message, and those are the row being written,
 * a buyer's name, email and address on an invoice insert. A driver repeats the value that collided with a
 * unique index, and a provider can echo a request field back. No erasure reaches that table, so a copy of a
 * person there would outlive the erasure that removed them everywhere else.
 *
 * So the stored text names the failure and leaves its data out: the class and its code, the statement with
 * its placeholders for a database exception, and the message only where this package raised the exception,
 * because those messages name references, documents and fields rather than people. The exception itself is
 * rethrown, so its full message still reaches the application's exception handler, where the application
 * decides what it keeps.
 */
final class RedactedError
{
    /** Long enough for a statement, short enough that the column stays a pointer rather than a log. */
    private const int LIMIT = 1_000;

    public static function of(Throwable $error, int $limit = self::LIMIT): string
    {
        return self::fit(self::describe($error), $limit);
    }

    /**
     * A failure text in a form its column accepts: at most `$limit` characters, and valid UTF-8.
     *
     * The text is often a provider's or a driver's, and either property fails the write that records the failure.
     * Both server engines refuse a value longer than a `string` column, MySQL in strict mode also one past the
     * 65,535 bytes of a `text` column, and both refuse bytes that are not UTF-8.
     */
    public static function fit(string $text, int $limit = self::LIMIT): string
    {
        return mb_substr(mb_scrub($text, 'UTF-8'), 0, $limit, 'UTF-8');
    }

    private static function describe(Throwable $error): string
    {
        // The statement with its placeholders, never the message: the message carries the bindings, the
        // connection and the driver's text, and the driver's text can repeat the value that collided.
        if ($error instanceof QueryException) {
            return sprintf('%s SQLSTATE[%s]: %s', $error::class, (string) $error->getCode(), $error->getSql());
        }

        if (self::raisedHere($error)) {
            return $error::class.': '.$error->getMessage();
        }

        $code = $error->getCode();

        return $code === 0 ? $error::class : sprintf('%s (code %s)', $error::class, (string) $code);
    }

    /** Whether the exception was constructed in this package's own source, where every message is written. */
    private static function raisedHere(Throwable $error): bool
    {
        return str_starts_with($error->getFile(), dirname(__DIR__).DIRECTORY_SEPARATOR);
    }
}
