<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use DateTimeInterface;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Pushery\Billing\Contracts\TrialNotifier;
use Pushery\Billing\Models\BillingEvent;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\Support\BillingEventLog;

/**
 * Warns owners whose GENERIC trial is about to end.
 *
 * ## The half the provider could never warn
 *
 * A subscription trial ends with a provider event — `customer.subscription.trial_will_end` — and the webhook
 * effect behind it has shipped for a long time. The generic trial has no provider at all: it is a date on
 * the owner's own row, written by `Trials::grant()` with a `save()` and nothing else. Nobody could send that
 * event, and nothing here looked for the date, so the trial simply ended.
 *
 * That is the mode WITHOUT a card, which is the one where the customer has no other signal — no failed
 * charge, no receipt, nothing. They lost access on the day, having been told nothing. For the operator it is
 * churn that appears in no error list.
 *
 * ## Once per trial END, not once per owner
 *
 * The mark is written to the audit log against the owner AND the date. Keyed on the owner alone, an extended
 * trial would be silenced for good; keyed on nothing, the customer is mailed every night the scheduler runs.
 * A trial moved to a new date is a new reminder, which is the same rule the webhook path applies.
 *
 * The mark is recorded only when something was actually sent. A dry run that recorded it would silence the
 * real run behind it — the two have to be skipped together or not at all.
 *
 * ## Owners with a subscription are skipped
 *
 * Not an optimization. Both paths firing means the same customer gets the same reminder twice, which is how
 * a reminder stops being read.
 */
final class WarnEndingTrialsCommand extends Command
{
    protected $signature = 'billing:trials:warn {--days= : Warn about trials ending within this many days} {--dry-run}';

    protected $description = 'Notify owners whose generic trial is about to end.';

    public function handle(Repository $config, TrialNotifier $notifier, BillingEventLog $log): int
    {
        $model = $config->get('billing.customer.model');

        if (! is_string($model) || ! is_a($model, Model::class, true)) {
            $this->components->warn('billing.customer.model is not configured; nothing to scan.');

            return self::SUCCESS;
        }

        $days = $this->windowDays($config);
        $dryRun = $this->option('dry-run') === true;
        $warned = 0;

        // From NOW, not from the start of the day: a trial that already ended is not ending soon, and a
        // reminder about it is a message about something already lost.
        $now = Carbon::now();

        $model::query()
            ->whereNotNull('trial_ends_at')
            ->whereBetween('trial_ends_at', [$now, $now->copy()->addDays($days)])
            ->chunkById(100,
                /** @param Collection<int, Model> $owners */
                function (Collection $owners) use ($notifier, $log, $dryRun, &$warned): void {
                    foreach ($owners as $owner) {
                        // Normalized rather than type-checked, and the difference matters. The consumer owns
                        // this model: a `trial_ends_at` cast is Cashier's convention, not something this
                        // package can require. Skipping an owner whose column came back as a string would
                        // silently exclude exactly the installation that did not adopt the convention — the
                        // quiet direction, and the one the query itself has already ruled out by requiring
                        // the column to be non-null and inside the window.
                        $raw = $owner->getAttribute('trial_ends_at');

                        // `DateTimeInterface`, not `Carbon`. The `immutable_datetime` cast is ordinary in a
                        // consumer's model and yields a `CarbonImmutable`, which is NOT a `Carbon` — matching
                        // on the concrete class would have silently skipped every installation using it.
                        if ($raw instanceof DateTimeInterface) {
                            $endsAt = Carbon::instance($raw);
                        } elseif (is_string($raw)) {
                            $endsAt = Carbon::parse($raw);
                        } else {
                            // Neither a date nor a string a date could be read from. The query required the
                            // column to be non-null and inside the window, so getting here means the model
                            // answers this attribute with something else entirely — a mutator, a value
                            // object. Skipped rather than guessed at: inventing a date would put a reminder
                            // on a day nobody chose.
                            continue;
                        }

                        if ($this->hasSubscription($owner)) {
                            continue;
                        }

                        if ($this->alreadyWarned($owner, $endsAt)) {
                            continue;
                        }

                        $warned++;

                        if ($dryRun) {
                            continue;
                        }

                        $notifier->trialEnding($owner, $endsAt);

                        $log->record('trial.ending_notice_sent', $owner, [
                            'trial' => 'generic',
                            'trial_ends_at' => $endsAt->format('Y-m-d'),
                        ]);
                    }
                });

        $this->components->info(($dryRun ? 'Would warn ' : 'Warned ')."{$warned} owner(s) about an ending trial.");

        return self::SUCCESS;
    }

    /**
     * Whether the provider path already covers this owner.
     *
     * ANY subscription, of any type and any status — not just a default one in trial. The question here is
     * whether a provider exists that could send the trial-will-end event, and one subscription is enough for
     * that. Narrowing it would send a second reminder to somebody the webhook already reminded.
     */
    private function hasSubscription(Model $owner): bool
    {
        return Subscription::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey())
            ->exists();
    }

    /** Whether this owner has already been told about THIS trial end. */
    private function alreadyWarned(Model $owner, Carbon $endsAt): bool
    {
        // Compared in PHP rather than through a JSON path in SQL, deliberately. The three engines this
        // package is proven on spell a JSON path differently and disagree about what is comparable, and a
        // dedupe that silently matched nothing on one of them would mail a customer every night — the
        // failure would appear only in production, on whichever engine the operator happens to run.
        $date = $endsAt->format('Y-m-d');

        return BillingEvent::query()
            ->where('type', 'trial.ending_notice_sent')
            ->where('subject_type', $owner->getMorphClass())
            ->where('subject_id', $owner->getKey())
            ->get()
            ->contains(static fn (BillingEvent $event): bool => ($event->payload['trial_ends_at'] ?? null) === $date);
    }

    private function windowDays(Repository $config): int
    {
        // `is_numeric` rather than `is_string`, and that is not pedantry. The CLI always hands a string, but
        // `Artisan::call(..., ['--days' => 14])` hands an int, and the stricter check discards it — the
        // caller then silently gets the configured default instead of the window they asked for. A setting
        // that is quietly ignored is worse than one that is refused.
        //
        // The card-expiry command carried the stricter check for a while and this comment said so. It no
        // longer does, and `BothWindowOptionsAcceptEitherFormTest` keeps the two from drifting apart again.
        $option = $this->option('days');

        if (is_numeric($option) && (int) $option > 0) {
            return (int) $option;
        }

        // The same window the app-shell banner nudges on, so the mail and the banner cannot disagree about
        // when a trial is "ending soon" — two numbers for one idea is two places to change it.
        $configured = $config->get('billing.trial.ending_within_days', 3);

        return is_numeric($configured) && (int) $configured > 0 ? (int) $configured : 3;
    }
}
