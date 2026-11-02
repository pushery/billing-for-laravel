<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Pushery\Billing\Consumer\AnnualTerms;
use Pushery\Billing\Consumer\CustomerTypes;
use Pushery\Billing\Contracts\BillingActionUrls;
use Pushery\Billing\Contracts\OwnerTimezone;
use Pushery\Billing\Enums\BillingAction;
use Pushery\Billing\Enums\BillingInterval;
use Pushery\Billing\Enums\CustomerType;
use Pushery\Billing\Events\AnnualTermEndingSoon;
use Pushery\Billing\Events\MonthlyContinuationBeginsSoon;
use Pushery\Billing\Events\MonthlyContinuationReminder;
use Pushery\Billing\Models\BillingEvent;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\Support\BillingEventLog;
use Pushery\Billing\ValueObjects\Money;

/**
 * Reminds customers of an annual term that ends soon, and consumers of the monthly contract it went on as.
 *
 * ## Three reminders, as events
 *
 * {@see AnnualTermEndingSoon} 38 days before a term ends, inside the five to six weeks a consumer is reminded in, for a
 * business as a courtesy. {@see MonthlyContinuationBeginsSoon} 14 days before a consumer's first monthly charge, which
 * falls on the day the term ends. {@see MonthlyContinuationReminder} every six months after that, while the consumer's
 * subscription goes on monthly. The application sends the mails; the events carry what they have to say.
 *
 * The days are not settings. They are the lead times the reminders are owed at, and a value somebody lowered would
 * remind too late to cancel in time.
 *
 * ## Once per term
 *
 * Each reminder writes a mark to the audit log naming the subscription and the day it is about, and is not sent again
 * for that day. A further year a customer booked ends on another day, and is reminded of again. A mark is written only
 * when something was sent, so a dry run does not silence the real run behind it.
 *
 * Meant to run daily.
 */
final class RemindAnnualTermsCommand extends Command
{
    /** Days before an annual term ends, inside the 35 to 40 days a consumer is reminded in. */
    private const int TERM_ENDING_DAYS = 38;

    /** Days before a consumer's first monthly charge. */
    private const int FIRST_CHARGE_DAYS = 14;

    /** Months between reminders of a monthly continuation. */
    private const int CONTINUATION_MONTHS = 6;

    private const string ENDING = 'annual_term.ending_reminded';

    private const string FIRST_CHARGE = 'annual_term.first_charge_reminded';

    private const string CONTINUATION = 'annual_term.continuation_reminded';

    protected $signature = 'billing:annual-terms:remind {--dry-run}';

    protected $description = 'Remind customers of an annual term that ends soon, and consumers of the monthly contract it went on as.';

    private bool $dryRun = false;

    private int $sent = 0;

    private CustomerTypes $types;

    private BillingActionUrls $urls;

    private OwnerTimezone $zones;

    private BillingEventLog $log;

    private AnnualTerms $terms;

    public function handle(CustomerTypes $types, BillingActionUrls $urls, OwnerTimezone $zones, BillingEventLog $log, AnnualTerms $terms): int
    {
        [$this->types, $this->urls, $this->zones, $this->log, $this->terms] = [$types, $urls, $zones, $log, $terms];
        $this->dryRun = $this->option('dry-run') === true;
        $now = CarbonImmutable::now();

        Subscription::model()::query()
            ->whereNull('ends_at')
            // A trial is not the term: its end is where the year begins, and nothing ends there to remind of.
            ->where('status', 'active')
            ->whereBetween('current_period_end', [$now, $now->addDays(self::TERM_ENDING_DAYS)])
            ->with('owner')
            ->chunkById(100, function (Collection $subscriptions) use ($now): void {
                /** @var Collection<int, Subscription> $subscriptions */
                foreach ($subscriptions as $subscription) {
                    $this->remindOfTheEnd($subscription, $now);
                }
            });

        $this->remindOfContinuations($now);

        $this->components->info(($this->dryRun ? 'Would send ' : 'Sent ')."{$this->sent} annual-term reminder(s).");

        return self::SUCCESS;
    }

    /** The reminder before a term ends, and for a consumer the one before the first monthly charge. */
    private function remindOfTheEnd(Subscription $subscription, CarbonImmutable $now): void
    {
        $owner = $subscription->getRelationValue('owner');
        $tierKey = $subscription->tier_key;
        $end = $subscription->current_period_end;

        if (! $owner instanceof Model || $tierKey === null || ! $end instanceof DateTimeInterface
            || ($subscription->billing_interval ?? $this->terms->plansFor($subscription)->planFor($tierKey)?->interval) !== BillingInterval::Year) {
            return;
        }

        $endsAt = CarbonImmutable::instance($end);

        // A term the customer booked a further year after goes on as a year, not as a month: nothing to remind of yet.
        if ($this->terms->furtherYearBooked($owner, $subscription, $endsAt)) {
            return;
        }

        $type = $subscription->customer_type ?? $this->types->of($owner);
        // A consumer's term goes on monthly only where the driver arranges it. Elsewhere it renews as a year, and the
        // reminder is the one a business gets: no monthly price, and no further year to book.
        $monthly = $type === CustomerType::Consumer && $this->terms->arranges($subscription) ? $this->monthlyPriceOf($subscription, $tierKey) : null;
        $planUrl = $this->urls->for($owner, BillingAction::Plan);
        $lastDay = $this->lastDayBefore($owner, $endsAt);

        if (! $this->marked(self::ENDING, $owner, $subscription, $endsAt)) {
            $this->send(self::ENDING, $owner, $subscription, $endsAt, new AnnualTermEndingSoon(
                $owner, $subscription, $tierKey, $type, $endsAt, $lastDay, $monthly, $planUrl,
                $monthly instanceof Money ? $planUrl : null,
            ));
        }

        if ($monthly instanceof Money && $endsAt->lessThanOrEqualTo($now->addDays(self::FIRST_CHARGE_DAYS))
            && ! $this->marked(self::FIRST_CHARGE, $owner, $subscription, $endsAt)) {
            $this->send(self::FIRST_CHARGE, $owner, $subscription, $endsAt, new MonthlyContinuationBeginsSoon(
                $owner, $subscription, $tierKey, $endsAt, $lastDay, $monthly, $planUrl,
            ));
        }
    }

    /**
     * The reminder every six months of a consumer's monthly continuation, counted from the end of the term it went on
     * from, which the reminder of that end recorded.
     */
    private function remindOfContinuations(CarbonImmutable $now): void
    {
        BillingEvent::model()::query()
            ->where('type', self::ENDING)
            ->orderBy('id')
            ->chunkById(100, function (Collection $marks) use ($now): void {
                /** @var Collection<int, BillingEvent> $marks */
                foreach ($marks as $mark) {
                    $this->remindOfContinuation($mark, $now);
                }
            });
    }

    private function remindOfContinuation(BillingEvent $mark, CarbonImmutable $now): void
    {
        $ended = is_string($mark->payload['day'] ?? null) ? CarbonImmutable::parse($mark->payload['day'], 'UTC') : null;
        $key = $mark->payload['subscription'] ?? null;
        $subscription = is_int($key) || is_string($key) ? Subscription::model()::query()->find($key) : null;
        $owner = $subscription?->getRelationValue('owner');
        $tierKey = $subscription?->tier_key;

        if (! $ended instanceof CarbonImmutable || ! $subscription instanceof Subscription || ! $owner instanceof Model
            || $tierKey === null || $subscription->ends_at !== null || $subscription->billing_interval !== BillingInterval::Month
            || ($subscription->customer_type ?? $this->types->of($owner)) !== CustomerType::Consumer) {
            return;
        }

        $months = intdiv((int) $ended->diffInMonths($now, true), self::CONTINUATION_MONTHS) * self::CONTINUATION_MONTHS;
        $due = $ended->addMonths($months);
        $monthly = $this->monthlyPriceOf($subscription, $tierKey);

        if ($months < self::CONTINUATION_MONTHS || ! $monthly instanceof Money || $this->marked(self::CONTINUATION, $owner, $subscription, $due)) {
            return;
        }

        $next = $subscription->current_period_end;

        $this->send(self::CONTINUATION, $owner, $subscription, $due, new MonthlyContinuationReminder(
            $owner, $subscription, $tierKey, $monthly,
            $next instanceof DateTimeInterface ? CarbonImmutable::instance($next) : null,
            $this->urls->for($owner, BillingAction::Plan),
        ));
    }

    /**
     * What a consumer's subscription costs each month after its term: the tier's monthly price, or a twelfth of the price
     * of the year, rounded down, for a subscription that began before its terms named a monthly price.
     */
    private function monthlyPriceOf(Subscription $subscription, string $tierKey): ?Money
    {
        $plans = $this->terms->plansFor($subscription);

        if ($subscription->monthly_terms_at !== null) {
            return $plans->planFor($tierKey, BillingInterval::Month)?->amount;
        }

        $year = $plans->planFor($tierKey, BillingInterval::Year)?->amount;

        return $year instanceof Money ? new Money(intdiv($year->minorUnits, 12), $year->currency) : null;
    }

    /**
     * The last calendar day before a moment, in the owner's time zone: a term that ends at midnight can be canceled until
     * the day before, and naming the day of the moment itself would promise a day too many.
     */
    private function lastDayBefore(Model $owner, CarbonImmutable $moment): string
    {
        return $moment->subSecond()->setTimezone($this->zones->for($owner) ?? 'UTC')->format('Y-m-d');
    }

    private function send(string $type, Model $owner, Subscription $subscription, CarbonImmutable $day, object $event): void
    {
        $this->sent++;

        if ($this->dryRun) {
            return;
        }

        Event::dispatch($event);

        $this->log->record($type, $owner, ['subscription' => $this->keyOf($subscription), 'day' => $day->format('Y-m-d')]);
    }

    /** Whether the reminder of this kind was sent already for this subscription and this day. */
    private function marked(string $type, Model $owner, Subscription $subscription, CarbonImmutable $day): bool
    {
        // Compared in PHP rather than through a JSON path in SQL: the engines spell a JSON path differently, and a check
        // that matched nothing on one of them would remind every day.
        $key = $this->keyOf($subscription);
        $date = $day->format('Y-m-d');

        return BillingEvent::model()::query()
            ->where('type', $type)
            ->where('subject_type', $owner->getMorphClass())
            ->where('subject_id', $owner->getKey())
            ->get()
            ->contains(static fn (BillingEvent $event): bool => ($event->payload['subscription'] ?? null) === $key
                && ($event->payload['day'] ?? null) === $date);
    }

    /** The subscription's key as text, for a log entry and a cache key. */
    private function keyOf(Subscription $subscription): string
    {
        $key = $subscription->getKey();

        return is_scalar($key) ? (string) $key : '';
    }
}
