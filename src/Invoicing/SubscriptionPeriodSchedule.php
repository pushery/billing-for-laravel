<?php

declare(strict_types=1);

namespace Pushery\Billing\Invoicing;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Pushery\Billing\Tax\SubscriptionPeriods;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\ServicePeriod;

/**
 * A subscription term cut into the periods it is actually supplied in.
 *
 * ## Why a term has to be cut at all
 *
 * A billing period is a PART-SUPPLY: an economically divisible service whose parts are separately agreed and
 * separately settled. That is not a bookkeeping preference — it is what lets each period carry its own
 * document with its own service period, and it is why a monthly subscription stays a small-value receipt
 * while the same contract billed once a year does not. The threshold is read per DOCUMENT, from its own
 * gross, never from what the contract is worth.
 *
 * ## The arithmetic that has to hold, and why it is the hard part
 *
 * Twelve periods of a 119.00 term are not twelve times 9.9166… — they are twelve whole-cent amounts that
 * must sum back to 119.00 exactly. Dividing and rounding each period independently loses or invents cents,
 * and over a year the drift lands in whichever direction the rounding happened to lean. So the split is done
 * ONCE, with `Money::allocate()`, which distributes the remainder deterministically rather than letting each
 * period round on its own.
 *
 * ## The dates, and the one that is easy to get wrong
 *
 * The end of a period is INCLUSIVE: the last day covered, not the first day of the next. Stated the other
 * way round, consecutive periods each claim the same day, and every document is internally consistent while
 * two of them lined up are not. `ServicePeriod` refuses the inverted case; this class guarantees the
 * stronger property the documents need — that consecutive periods TOUCH, with no gap and no overlap.
 *
 * ## What this does not decide
 *
 * When tax on those periods becomes due. A term paid up front is taxed on receipt rather than as it is
 * supplied, and that is a rule of the jurisdiction's profile, not of the calendar. This class answers only
 * which periods exist and what each is worth.
 */
final readonly class SubscriptionPeriodSchedule
{
    /**
     * Cut a term into `$periods` consecutive monthly periods, sharing its price without drift.
     *
     * @param  Money  $term  the whole term's price — the figure the periods must sum back to
     * @param  CarbonImmutable  $start  the first day of the first period
     * @param  int  $periods  how many periods the term is supplied in
     * @return list<ServicePeriod>
     */
    public static function monthly(Money $term, CarbonImmutable $start, int $periods): array
    {
        if ($periods < 1) {
            throw new InvalidArgumentException("A term is supplied in at least one period; got {$periods}.");
        }

        // The cut itself lives in SubscriptionPeriods, which is the one implementation of it. There used to
        // be two — this method walked the calendar with `addMonth()`, accumulating each boundary from the one
        // before — and the second one was wrong in a way that reached a release:
        //
        // `addMonth()` OVERFLOWS. In Carbon, 31 January plus a month is 3 March, not the last day of the
        // shorter month, so a term beginning on a 29th, 30th or 31st produced a first period that swallowed
        // February whole and a start date that walked forward three days and never came back. Nothing about
        // it looked wrong: the periods still touched, the shares still summed to the term, and the totals
        // still reconciled — while the dates on the documents said something false. And they are not display
        // text: they are BT-73/BT-74 on an XRechnung, and they set the invoice date that decides which return
        // the supply falls into.
        //
        // The fix is not `addMonthNoOverflow()` on the accumulation, which stops the swallowing and then
        // walks the anchor day BACKWARDS forever from the first short month. Every boundary is measured from
        // the original start, which is what brings the 31st back the moment a month has one.
        return new SubscriptionPeriods()->split($start, $periods, $term);
    }

    /**
     * Whether a schedule covers its term with no gap and no overlap.
     *
     * Written as a question rather than trusted from the construction above, because it is the property the
     * DOCUMENTS make a claim about: twelve receipts that each look right can still describe eleven months or
     * thirteen. Anything that builds periods some other way — a migrated contract, a consumer's own
     * schedule — can be held to the same standard by asking here.
     *
     * @param  list<ServicePeriod>  $schedule
     */
    public static function isContiguous(array $schedule): bool
    {
        $previous = null;

        foreach ($schedule as $period) {
            if ($previous instanceof ServicePeriod && ! $period->followsDirectly($previous)) {
                return false;
            }

            $previous = $period;
        }

        return $previous instanceof ServicePeriod;
    }

    /**
     * What the schedule's periods come to together.
     *
     * Exists so a caller can assert the identity that matters — this equals the term — rather than trusting
     * that it does. A schedule whose parts do not sum to the whole is the failure this class is built to
     * prevent, and it is invisible in any single document.
     *
     * @param  list<ServicePeriod>  $schedule
     */
    public static function total(array $schedule, string $currency): Money
    {
        $total = Money::zero($currency);

        foreach ($schedule as $period) {
            $total = $total->plus($period->amount);
        }

        return $total;
    }
}
