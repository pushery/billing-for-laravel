<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Carbon\CarbonImmutable;

/**
 * A period a special tax return declares: a quarter for the one-stop-shop return, a month for the import scheme's.
 *
 * The return asks the same questions of either. When it was due, which decides how long a correction to it may
 * still be declared; whether two periods are the same, which separates a correction from the period's own sales;
 * which period of the same length holds a moment, which is where a corrected document was declared; and a label
 * a file and a refusal can name it by.
 */
interface TaxReturnPeriod
{
    /** The year the period falls in, four digits. */
    public int $year { get; }

    /** The quarter the period falls in, the period itself for a quarterly return. */
    public int $quarter { get; }

    /** The last moment the return for this period could be filed on time. */
    public function dueOn(): CarbonImmutable;

    /** Whether a correction to this period may still be declared, given a window in years counted from the due date. */
    public function correctableOn(CarbonImmutable $moment, int $windowYears): bool;

    public function equals(self $other): bool;

    /** A stable label for an export line and a file name. */
    public function label(): string;

    /** The period of the same length that holds a moment, which is where a corrected document was declared. */
    public function periodContaining(CarbonImmutable $moment): self;
}
