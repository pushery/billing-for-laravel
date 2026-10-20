<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * The file a records export writes, and the days it is asked for.
 *
 * Records are written semicolon-separated, like the returns this package writes: a header of column names and one
 * line per row, with a field quoted where it holds a semicolon, a quote or a line break, since a description of goods
 * is free text. The days are calendar days as YYYY-MM-DD, read in UTC.
 */
final class RecordsFile
{
    /**
     * The file of these rows under these columns, and how many rows it holds.
     *
     * @param  list<string>  $columns
     * @param  iterable<array<string, string>>  $rows
     * @return array{string, int}
     */
    public static function render(array $columns, iterable $rows): array
    {
        $handle = fopen('php://temp', 'w+') ?: throw new RuntimeException('No buffer could be opened for the records.');
        $count = 0;

        fputcsv($handle, $columns, ';', '"', '');

        foreach ($rows as $row) {
            fputcsv($handle, array_values($row), ';', '"', '');
            $count++;
        }

        rewind($handle);
        $contents = (string) stream_get_contents($handle);
        fclose($handle);

        return [$contents, $count];
    }

    /** A calendar day written as YYYY-MM-DD, at its start in UTC, or null where the value is no such day. */
    public static function day(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1 || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }

        return CarbonImmutable::create((int) $parts[1], (int) $parts[2], (int) $parts[3], 0, 0, 0, 'UTC');
    }
}
