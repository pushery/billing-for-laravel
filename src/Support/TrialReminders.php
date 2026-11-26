<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\TrialNotifier;
use Pushery\Billing\Models\BillingEvent;

/**
 * The reminder before the end of a trial, once for each end of it, whichever path sends it first.
 *
 * `billing:trials:warn` sends the reminders of the trials the package keeps: a generic trial on the owner's own row,
 * and a subscription trial no provider announces. `remind()` is the same reminder for a trial the package does not
 * keep, such as one an application runs for an order that went on without a payment method: it goes through the bound
 * `TrialNotifier` and records the same mark, so the command and the application never both remind one end.
 *
 * A reminder is due when the trial ends inside the window of days the command counts from now. Where `OwnerTimezone`
 * answers a zone for the owner, the window counts whole days there: the trial is due once its last day lies no more
 * than that many days after today, in the owner's zone, and is due the whole of that day.
 */
final readonly class TrialReminders
{
    /** The type of the mark in the audit log, shared with the provider's own notice. */
    public const string SENT = 'trial.ending_notice_sent';

    public function __construct(
        private TrialNotifier $notifier,
        private BillingEventLog $log,
    ) {}

    /**
     * Remind an owner of a trial that ends at this moment, unless that end was reminded before. Returns whether the
     * reminder went out now.
     */
    public function remind(Model $owner, DateTimeInterface $endsAt): bool
    {
        if ($this->sentFor($owner, $endsAt)) {
            return false;
        }

        $this->send($owner, $endsAt, 'application');

        return true;
    }

    /**
     * Send the reminder and record its mark, whatever was sent before. `$trial` names the path in the mark: `generic`,
     * `subscription` or `application`.
     */
    public function send(Model $owner, DateTimeInterface $endsAt, string $trial): void
    {
        $this->notifier->trialEnding($owner, $endsAt);

        $this->log->record(self::SENT, $owner, [
            'trial' => $trial,
            'trial_ends_at' => $endsAt->format('Y-m-d'),
        ]);
    }

    /** Whether this owner has been reminded of this end of a trial. */
    public function sentFor(Model $owner, DateTimeInterface $endsAt): bool
    {
        // Compared in PHP rather than through a JSON path in SQL, deliberately. The three engines this package is
        // proven on spell a JSON path differently and disagree about what is comparable, and a dedupe that silently
        // matched nothing on one of them would mail a customer every night.
        $date = $endsAt->format('Y-m-d');

        return BillingEvent::model()::query()
            ->where('type', self::SENT)
            ->where('subject_type', $owner->getMorphClass())
            ->where('subject_id', $owner->getKey())
            ->get()
            ->contains(static fn (BillingEvent $event): bool => ($event->payload['trial_ends_at'] ?? null) === $date);
    }

    /** Whether a trial that ends at this moment is due its reminder now, with a window of this many days. */
    public function due(Model $owner, DateTimeInterface $endsAt, CarbonInterface $now, int $days): bool
    {
        $end = CarbonImmutable::instance($endsAt);

        // A trial that already ended is not ending soon, and a reminder about it is a message about something lost.
        if ($end->lessThanOrEqualTo($now)) {
            return false;
        }

        $zone = OwnersZone::zoneOf($owner);

        if ($zone === null) {
            return $end->lessThanOrEqualTo(CarbonImmutable::instance($now)->addDays($days));
        }

        return $end->setTimezone($zone)->startOfDay()->lessThanOrEqualTo(CarbonImmutable::instance($now)->setTimezone($zone)->startOfDay()->addDays($days));
    }
}
