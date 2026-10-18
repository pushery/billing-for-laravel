<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * When a person declared a contract should end through the cancellation button.
 *
 * `EarliestPossible` is also what the law reads into a declaration that names no time at all, so a page offers it
 * first. `OnDate` carries the date the person chose.
 */
enum CancellationTiming: string
{
    case EarliestPossible = 'earliest_possible';
    case OnDate = 'on_date';
}
