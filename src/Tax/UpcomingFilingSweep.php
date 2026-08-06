<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Pushery\Billing\Enums\FilingObligation;
use Pushery\Billing\Events\FilingObligationApproaching;
use Pushery\Billing\Models\FilingReminder;
use Pushery\Billing\ValueObjects\ReportingPeriod;

/**
 * Announces each filing obligation once, before its day arrives.
 *
 * ## What the calendar could not do on its own
 *
 * {@see FilingCalendar} answers "what is due on this day" and says in its own docblock that its purpose is
 * to make sure the day does not arrive unannounced. It had no caller: nothing asked it, so nothing was
 * announced, and the day arrived exactly as unannounced as before.
 *
 * A calendar answers about a day. A warning has to look FORWARD, which is the small thing that was missing:
 * this walks the days inside the notice window and asks the calendar about each.
 *
 * ## Once per obligation, and that is the whole point
 *
 * The last period's return and the annual seller report fall due on the SAME date. A sweep that remembered
 * "the 31st is announced" would silence the second one — the precise failure the calendar was written to
 * prevent. So the marker is keyed on the obligation AND the date, and two announcements for one day are the
 * correct shape rather than a duplicate.
 *
 * ## It warns; nothing here files
 *
 * This package submits nothing to any authority. Being reminded is all it can offer, and being reminded
 * about one obligation must never count as having been reminded about the other.
 */
final readonly class UpcomingFilingSweep
{
    public function __construct(
        private Repository $config,
        private Dispatcher $events,
        private FilingCalendar $calendar,
    ) {}

    /**
     * Announce everything falling due inside the notice window.
     *
     * @return int how many obligations were announced
     */
    public function announce(CarbonImmutable $now): int
    {
        $announced = 0;
        $today = $now->startOfDay();

        for ($offset = 0; $offset <= $this->noticeDays(); $offset++) {
            $day = $today->addDays($offset);

            foreach ($this->calendar->dueOn($day) as $obligation) {
                if ($this->alreadyAnnounced($obligation['obligation'], $obligation['due_on'])) {
                    continue;
                }

                $this->events->dispatch(new FilingObligationApproaching(
                    obligation: $obligation['obligation'],
                    dueOn: $obligation['due_on'],
                    period: $obligation['period'] instanceof ReportingPeriod ? $obligation['period'] : null,
                    // Whole days, from the start of today: a warning that said "0.6 days" would be answering
                    // a question nobody asked, and the recipient phrases its own urgency from this.
                    daysRemaining: $offset,
                ));

                // Marked after dispatching. A crash between the two announces once more, which somebody can
                // live with; the other order loses the announcement, which is what this class exists to
                // prevent.
                FilingReminder::query()->create([
                    'obligation' => $obligation['obligation'],
                    'due_on' => $obligation['due_on']->toDateString(),
                    'announced_at' => $now,
                ]);

                $announced++;
            }
        }

        return $announced;
    }

    private function alreadyAnnounced(FilingObligation $obligation, CarbonImmutable $dueOn): bool
    {
        return FilingReminder::query()
            ->where('obligation', $obligation->value)
            ->whereDate('due_on', $dueOn->toDateString())
            ->exists();
    }

    /** How many days ahead an obligation is announced. */
    private function noticeDays(): int
    {
        $days = $this->config->get('billing.reporting.filing_notice_days', 14);

        return is_int($days) && $days > 0 ? $days : 14;
    }
}
