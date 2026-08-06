<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use InvalidArgumentException;
use Pushery\Billing\Enums\TaxRateCategory;

/**
 * One line of a periodic tax return: what was sold into one country, at one rate, in one period.
 *
 * The origin period is part of the line rather than an optional extra, and that is the whole shape of the
 * thing. A return declares this period's sales AND corrections to earlier ones, and the only difference
 * between the two is which period a line belongs to. Leaving it off the line would mean a correction is
 * whatever the exporter happens to remember it is.
 *
 * A correcting line is NEGATIVE and names the period it corrects; a current line names none. A correction is
 * never written back into the period it corrects — that return has been filed, and a file that changes after
 * it was filed is not a filing.
 */
final readonly class TaxReturnLine
{
    public function __construct(
        /** The country whose tax this is. */
        public string $country,
        public TaxRateCategory $category,
        /** The rate actually applied to the sales, in basis points. */
        public int $rateBps,
        /** The taxable base. Negative on a correcting line. */
        public int $netMinor,
        /** The tax on it. Negative on a correcting line. */
        public int $taxMinor,
        /** The period this corrects, or null when it declares this period's own sales. */
        public ?ReportingPeriod $originPeriod = null,
    ) {
        if ($country === '' || strlen($country) !== 2) {
            throw new InvalidArgumentException("A return line names a two-letter country; got '{$country}'.");
        }

        if ($rateBps < 0 || $rateBps > 10_000) {
            throw new InvalidArgumentException("A rate runs from 0 to 10000 basis points; got {$rateBps}.");
        }

        if (($netMinor < 0) !== ($taxMinor < 0) && $netMinor !== 0 && $taxMinor !== 0) {
            throw new InvalidArgumentException(
                'A line\'s base and its tax move together: both declare a sale or both correct one. Opposite '
                .'signs would net a correction against a sale inside a single line, where nothing could see it.'
            );
        }
    }

    /** Whether this line corrects an earlier period rather than declaring this one. */
    public function corrects(): bool
    {
        return $this->originPeriod instanceof ReportingPeriod;
    }

    /** The key a line aggregates on: one country, one category, one rate, one origin. */
    public function key(): string
    {
        return implode('|', [
            $this->country,
            $this->category->value,
            $this->rateBps,
            $this->originPeriod?->label() ?? 'current',
        ]);
    }
}
