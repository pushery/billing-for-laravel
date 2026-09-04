<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use DateTimeInterface;
use Illuminate\Console\Command;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Pushery\Billing\Contracts\TrialNotifier;
use Pushery\Billing\Enums\SubscriptionState;
use Pushery\Billing\Models\BillingEvent;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\Support\BillingEventLog;
use Pushery\Billing\Support\BillingManager;
use Throwable;

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

        $warned += $this->warnSubscriptionTrials($notifier, $log, $now, $days, $dryRun);

        $this->components->info(($dryRun ? 'Would warn ' : 'Warned ')."{$warned} owner(s) about an ending trial.");

        return self::SUCCESS;
    }

    /**
     * The SUBSCRIPTION trials, which the scan above cannot see.
     *
     * That one reads the OWNER's `trial_ends_at` — the generic trial, a date on a model with no
     * subscription behind it. A subscription trial keeps its date on the subscription row instead, and
     * until a local-engine driver existed nobody had to look there: the only driver that created one was
     * Stripe, and Stripe announces its own trial ends.
     *
     * A local engine announces nothing, because nothing at the provider knows a trial is running. The
     * package holds the date and its own sweep collects the first charge — so without this the customer's
     * first notice of the end of their free period is the debit.
     */
    private function warnSubscriptionTrials(
        TrialNotifier $notifier,
        BillingEventLog $log,
        Carbon $now,
        int $days,
        bool $dryRun,
    ): int {
        $warned = 0;

        Subscription::query()
            ->where('status', SubscriptionState::Trialing->value)
            ->whereNotNull('trial_ends_at')
            ->whereBetween('trial_ends_at', [$now, $now->copy()->addDays($days)])
            ->chunkById(100, function (Collection $subscriptions) use ($notifier, $log, $dryRun, &$warned): void {
                /** @var Collection<int, Subscription> $subscriptions */
                foreach ($subscriptions as $subscription) {
                    if ($this->providerAnnouncesTrialEnd($subscription->provider)) {
                        continue;
                    }

                    $endsAt = $subscription->trial_ends_at;
                    $owner = $this->ownerOf($subscription);

                    if (! $endsAt instanceof DateTimeInterface || ! $owner instanceof Model) {
                        continue;
                    }

                    $endsAt = Carbon::instance($endsAt);

                    if ($this->alreadyWarned($owner, $endsAt)) {
                        continue;
                    }

                    $warned++;

                    if ($dryRun) {
                        continue;
                    }

                    $notifier->trialEnding($owner, $endsAt);

                    // The SAME event type and the same date format the generic path records, so the two
                    // dedupe against each other and the provider's own notice does too — it writes this
                    // record as well. A second type would make three paths that each believe they are the
                    // only one.
                    $log->record('trial.ending_notice_sent', $owner, [
                        'trial' => 'subscription',
                        'trial_ends_at' => $endsAt->format('Y-m-d'),
                    ]);
                }
            });

        return $warned;
    }

    /**
     * Whether this subscription's provider tells the customer their trial is ending.
     *
     * Asked of the DRIVER's declared capability rather than of its name, because the answer is a property
     * of what that driver actually wires: Stripe's mapper produces the event and its provider registers the
     * notice on it. A driver that cannot be resolved — a subscription left behind by a provider the install
     * no longer configures — is treated as announcing NOTHING, which is the safe direction: a duplicate
     * reminder costs an email, and a missing one costs an unannounced charge.
     */
    private function providerAnnouncesTrialEnd(string $provider): bool
    {
        // No null guard, because `billing_subscriptions.provider` is NOT NULL and the model types it
        // `string`. One would be a branch no run can enter, which reads as a guard and protects nothing.
        try {
            return Container::getInstance()->make(BillingManager::class)
                ->driver($provider)
                ->capabilities()
                ->supportsProviderTrialNotice;
        } catch (Throwable) {
            return false;
        }
    }

    /** The consumer's model behind a subscription, through the morph map. */
    private function ownerOf(Subscription $subscription): ?Model
    {
        $class = Relation::getMorphedModel($subscription->owner_type) ?? $subscription->owner_type;

        if (! class_exists($class) || ! is_a($class, Model::class, true)) {
            return null;
        }

        return $class::query()->find($subscription->owner_id);
    }

    /**
     * Whether this owner's GENERIC trial reminder would be wrong or redundant.
     *
     * TWO independent reasons, and collapsing them into one is a mistake this method has now made in both
     * directions.
     *
     * It began as "any subscription at all", reasoned as "a provider exists that could send the
     * trial-will-end event". That reasoning stopped being true when a local-engine driver arrived: nothing
     * at such a provider knows a trial is running, so it announces nothing.
     *
     * Replacing it with the capability question ALONE was worse, because it threw away the other reason.
     * An owner's `trial_ends_at` is written by `Trials::grant()` and cleared by nothing — not by
     * subscribing — so somebody who converts DURING their generic trial keeps a future date on their own
     * row. Asking only about the provider, they stopped being skipped and were mailed "add a payment
     * method before it ends" while already paying, with a mandate on file.
     *
     * So: a LIVE subscription skips them because they converted, whatever the provider; and a subscription
     * under a provider that announces skips them because the webhook is about to say it. A TERMINAL row
     * under a silent provider skips neither — that owner really is on a generic trial that nobody else
     * will mention.
     */
    private function hasSubscription(Model $owner): bool
    {
        return Subscription::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey())
            ->get()
            ->contains(fn (Subscription $subscription): bool =>
                // They already converted. The generic trial ending is not something they have to act on,
                // and the notice tells them to add a payment method they demonstrably have.
                ! $subscription->isReplaceableByANewSubscription()
                // Or a provider will tell them, and two reminders for one trial end is how a reminder
                // stops being read.
                || $this->providerAnnouncesTrialEnd($subscription->provider));
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
