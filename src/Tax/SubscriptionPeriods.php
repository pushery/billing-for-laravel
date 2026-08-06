<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\ServicePeriod;

/**
 * A subscription term cut into the periods it is actually billed in.
 *
 * Each period is separately agreed and separately billed, which is what makes it a supply in its own right
 * rather than a twelfth of one. That has two consequences worth stating, because both are easy to lose:
 *
 * **The periods must meet exactly.** One ending on the day the next begins double-counts a day; one ending a
 * day early leaves a gap. Neither is visible in any total — the amounts still add up — and both are wrong on
 * a document a reader is entitled to rely on.
 *
 * **The money must not drift.** Splitting a term by dividing and rounding each part loses or gains cents
 * against the contract, and over twelve periods the difference is real money nobody can trace. The split is
 * therefore an allocation of the whole, which distributes the remainder rather than creating it.
 */
final readonly class SubscriptionPeriods
{
    /**
     * Cut a term into equal billing periods, each carrying its own share of the amount.
     *
     * @param  int  $count  how many periods the term is billed in
     * @return list<ServicePeriod>
     */
    public function split(CarbonImmutable $startsOn, int $count, Money $total): array
    {
        if ($count < 1) {
            throw new InvalidArgumentException("A term is billed in at least one period; got {$count}.");
        }

        // An allocation of the whole, not a division of it: the remainder is handed out among the periods
        // rather than appearing as a difference against the contract.
        $shares = $total->allocate(...array_fill(0, $count, 1));
        $periods = [];

        for ($index = 0; $index < $count; $index++) {
            // NoOverflow, and every boundary measured from the ORIGINAL start. Adding a month to a 31st
            // otherwise lands in the month AFTER next, which does not merely shift a boundary — it makes one
            // period swallow another, and the totals still add up while the dates say something false.
            // Anchoring on the start also keeps the 31st a 31st wherever the month has one, instead of
            // walking backwards a day at a time from the first short month.
            $periods[] = new ServicePeriod(
                $startsOn->addMonthsNoOverflow($index)->startOfDay(),
                // The last day of this period, not the first of the next: an inclusive end that meets the
                // next start exactly, with neither a shared day nor a gap between them.
                $startsOn->addMonthsNoOverflow($index + 1)->subDay()->endOfDay(),
                $shares[$index],
            );
        }

        return $periods;
    }
}
