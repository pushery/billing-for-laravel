<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;

/**
 * A published rate feed did not have the shape its service documents.
 *
 * Thrown rather than worked around, and the whole import is refused rather than partially applied. Skipping
 * the rows that would not parse is the tempting alternative and it is how a series ends up with a hole
 * nobody notices: the reader's forward walk then answers the missing day with a neighboring day's rate,
 * which is a real figure for the wrong date. A refused import is visible in a scheduler; a quietly short one
 * is visible nowhere.
 */
final class ExchangeRateFeedUnreadable extends RuntimeException
{
    public static function empty(): self
    {
        return new self(
            'The exchange-rate feed returned nothing at all. That is different from a period with no '
            .'observations, which returns a header and no rows — an empty body means the request did not '
            .'reach the service it was meant for.'
        );
    }

    /** @param  list<string>  $found */
    public static function missingColumn(string $column, array $found): self
    {
        return new self(
            "The exchange-rate feed has no {$column} column (found: ".implode(', ', $found).'). Columns are '
            .'read by name rather than position on purpose — the service may add fields, and a positional '
            .'read would take a rate out of whichever one moved into that slot, silently and plausibly.'
        );
    }

    public static function unreadableRow(string $line): self
    {
        return new self(
            "An exchange-rate row could not be read: {$line}. The import is refused rather than continued "
            .'without it: a skipped row leaves a hole in the series, and the next lookup for that day is '
            .'answered by the following publication day — a real rate, for the wrong date.'
        );
    }
}
