<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Carbon;
use Pushery\Billing\Enums\FilingObligation;
use Pushery\Billing\ValueObjects\ReportingPeriod;

/**
 * What is due when, and which obligations land on the same day.
 *
 * ## The collision is the reason this exists
 *
 * The last period of a year and the annual seller report fall due on the same date. That is not a curiosity:
 * it is the setup for the failure where somebody files "the thing due at the end of January", ticks it off,
 * and discovers in a letter months later that the other one was never sent. Different law, different data,
 * one calendar day.
 *
 * So the calendar reports them as what they are — two obligations sharing a date — and every key they touch
 * is per-obligation. Nothing here lets a caller ask for "the filings due today" and receive one job: what it
 * returns is a list, and each entry carries its own lock and its own acknowledgement.
 *
 * ## It warns, it does not file
 *
 * Nothing in this package submits anything to any authority or holds credentials for one. What it can do is
 * make sure the day does not arrive unannounced, and that being reminded once does not silence the reminder
 * for the obligation nobody has touched.
 */
final readonly class FilingCalendar
{
    /**
     * @param  ?Repository  $config  resolved from the container when absent, so the several call sites that
     *                               build this calendar directly keep working
     */
    public function __construct(private ?Repository $config = null) {}

    /**
     * Everything falling due on a given day, as separate obligations.
     *
     * @return list<array{obligation: FilingObligation, period: ?ReportingPeriod, due_on: CarbonImmutable}>
     */
    public function dueOn(CarbonInterface $day): array
    {
        $due = [];
        $moment = CarbonImmutable::instance(Carbon::instance($day))->startOfDay();

        foreach ($this->quartersAround($moment) as $period) {
            if ($period->dueOn()->startOfDay()->equalTo($moment)) {
                $due[] = ['obligation' => FilingObligation::PeriodicReturn, 'period' => $period, 'due_on' => $period->dueOn()];
            }
        }

        // ONLY where there are sellers to report. The annual report is about what other people earned
        // through the platform, so an installation that sells nothing but its own products has no such
        // obligation — and being warned about a duty you do not have is not a harmless extra. It sends
        // somebody looking, once a year, for a filing interface they have no account for.
        //
        // The periodic return above is deliberately NOT gated with it. That one is the platform's own, it
        // exists whether or not anybody else sells through the platform, and the two share a date — so a
        // gate hung one line higher would trade a wrong reminder for a missing one, which is the expensive
        // direction.
        $annual = $this->annualReportDueIn($moment->year);

        if ($this->marketplaceEnabled() && $annual->startOfDay()->equalTo($moment)) {
            $due[] = ['obligation' => FilingObligation::AnnualSellerReport, 'period' => null, 'due_on' => $annual];
        }

        return $due;
    }

    /**
     * Whether this installation reports on other people's sales at all.
     *
     * Read through the container when nobody handed a repository in, so a caller that constructs the
     * calendar directly still gets the installation's real answer rather than a default that would put the
     * obligation back on every install by the side door.
     */
    private function marketplaceEnabled(): bool
    {
        $config = $this->config ?? Container::getInstance()->make(Repository::class);

        return $config->get('billing.marketplace.enabled') === true;
    }

    /**
     * Whether more than one obligation falls on this day — the case a single run would silently merge.
     */
    public function collidesOn(CarbonInterface $day): bool
    {
        return count($this->dueOn($day)) > 1;
    }

    /** When the annual seller report is due for a year: the same day the last period's return is. */
    public function annualReportDueIn(int $year): CarbonImmutable
    {
        return new ReportingPeriod($year - 1, 4)->dueOn();
    }

    /**
     * The periods whose returns could plausibly fall on a day: the one just ended and its neighbors.
     *
     * Bounded deliberately. Walking every period since the beginning of time to find one due date would grow
     * without limit and answer the same question.
     *
     * @return list<ReportingPeriod>
     */
    private function quartersAround(CarbonImmutable $moment): array
    {
        $periods = [];

        foreach ([-2, -1, 0] as $offset) {
            $anchor = $moment->addMonthsNoOverflow(3 * $offset);
            $periods[] = ReportingPeriod::containing($anchor);
        }

        return $periods;
    }
}
