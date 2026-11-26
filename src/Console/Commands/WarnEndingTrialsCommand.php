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
use Pushery\Billing\Enums\SubscriptionState;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\Support\BillingManager;
use Pushery\Billing\Support\SweepAttempts;
use Pushery\Billing\Support\TableLookup;
use Pushery\Billing\Support\TrialReminders;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Throwable;

/**
 * Warns owners whose generic trial is about to end.
 *
 * ## The half the provider could never warn
 *
 * A subscription trial ends with a provider event — `customer.subscription.trial_will_end` — and the webhook
 * effect behind it has shipped for a long time. The generic trial has no provider at all: it is a date on
 * the owner's own row, written by `Trials::grant()` with a `save()` and nothing else. Nobody could send that
 * event, and nothing here looked for the date, so the trial ended.
 *
 * That is the mode without a card, which is the one where the customer has no other signal — no failed
 * charge, no receipt, nothing. They lost access on the day, having been told nothing. For the operator it is
 * churn that appears in no error list.
 *
 * ## Once per trial END, not once per owner
 *
 * The mark is written to the audit log against the owner and the date. Keyed on the owner alone, an extended
 * trial would be silenced for good; keyed on nothing, the customer is mailed every night the scheduler runs.
 * A trial moved to a new date is a new reminder, which is the same rule the webhook path applies. The mark is
 * kept by `TrialReminders`, through which an application reminds a trial of its own as well.
 *
 * ## The window, in the owner's days
 *
 * A trial is due when it ends inside the window of days counted from now, and where `OwnerTimezone` answers a zone
 * for the owner, the window counts whole days there (`TrialReminders::due()`). The query reads one day further, so
 * the last day of the window is read whole in every zone.
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

    public function handle(Repository $config, TrialReminders $reminders): int
    {
        $days = $this->windowDays($config);
        $dryRun = $this->option('dry-run') === true;

        // From now, not from the start of the day: a trial that already ended is not ending soon, and a
        // reminder about it is a message about something already lost.
        $now = Carbon::now();

        // Each half on its own: a customer model the generic scan cannot read never keeps the subscription
        // trials, which live on the package's own table, from their reminder.
        $attempts = new SweepAttempts;
        $warned = $this->warnGenericTrials($config, $reminders, $attempts, $now, $days, $dryRun);
        $warned += $this->warnSubscriptionTrials($reminders, $attempts, $now, $days, $dryRun);

        $this->components->info(OutputFormatter::escape(($dryRun ? 'Would warn ' : 'Warned ')."{$warned} owner(s) about an ending trial."));

        $attempts->finish();

        return self::SUCCESS;
    }

    /**
     * The generic trials: the owners whose own `trial_ends_at` ends inside the window. Without a customer model,
     * or on a customer table without that column, there is no generic trial to scan, and the run says so instead
     * of failing before the subscription trials are reached. The column is looked up in the table a query on the
     * customer's connection finds, which on PostgreSQL may sit in another schema of the `search_path` than the first.
     */
    private function warnGenericTrials(Repository $config, TrialReminders $reminders, SweepAttempts $attempts, Carbon $now, int $days, bool $dryRun): int
    {
        $model = $config->get('billing.customer.model');

        if (! is_string($model) || ! is_a($model, Model::class, true)) {
            $this->components->warn('billing.customer.model is not configured; no generic trial was scanned.');

            return 0;
        }

        $customer = new $model;

        if (! $customer->getConnection()->getSchemaBuilder()->hasColumn(
            TableLookup::qualified($customer->getTable(), $customer->getConnectionName()),
            'trial_ends_at',
        )) {
            $this->components->warn(OutputFormatter::escape("The table {$customer->getTable()} has no trial_ends_at column; no generic trial was scanned."));

            return 0;
        }

        $warned = 0;

        $model::query()
            ->whereNotNull('trial_ends_at')
            ->whereBetween('trial_ends_at', [$now, $now->copy()->addDays($days + 1)])
            ->chunkById(100,
                /** @param Collection<int, Model> $owners */
                function (Collection $owners) use ($reminders, $attempts, $now, $days, $dryRun, &$warned): void {
                    foreach ($owners as $owner) {
                        // Normalized rather than type-checked, and the difference matters. The consumer owns
                        // this model: a `trial_ends_at` cast is Cashier's convention, not something this
                        // package can require. Skipping an owner whose column came back as a string would
                        // silently exclude exactly the installation that did not adopt the convention — the
                        // quiet direction, and the one the query itself has already ruled out by requiring
                        // the column to be non-null and inside the window.
                        $raw = $owner->getAttribute('trial_ends_at');

                        // `DateTimeInterface`, not `Carbon`. The `immutable_datetime` cast is ordinary in a
                        // consumer's model and yields a `CarbonImmutable`, which is not a `Carbon` — matching
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

                        if (! $reminders->due($owner, $endsAt, $now, $days) || $this->hasSubscription($owner)) {
                            continue;
                        }

                        if ($reminders->sentFor($owner, $endsAt)) {
                            continue;
                        }

                        if ($dryRun) {
                            $warned++;

                            continue;
                        }

                        $sent = $attempts->attempt(static function () use ($reminders, $owner, $endsAt): void {
                            $reminders->send($owner, $endsAt, 'generic');
                        });

                        if ($sent) {
                            $warned++;
                        }
                    }
                });

        return $warned;
    }

    /**
     * The subscription trials, which the scan above cannot see.
     *
     * That one reads the owner's `trial_ends_at` — the generic trial, a date on a model with no
     * subscription behind it. A subscription trial keeps its date on the subscription row instead, and
     * until a local-engine driver existed nobody had to look there: the only driver that created one was
     * Stripe, and Stripe announces its own trial ends.
     *
     * A local engine announces nothing, because nothing at the provider knows a trial is running. The
     * package holds the date and its own sweep collects the first charge — so without this the customer's
     * first notice of the end of their free period is the debit.
     */
    private function warnSubscriptionTrials(
        TrialReminders $reminders,
        SweepAttempts $attempts,
        Carbon $now,
        int $days,
        bool $dryRun,
    ): int {
        $warned = 0;

        Subscription::model()::query()
            ->where('status', SubscriptionState::Trialing->value)
            ->whereNotNull('trial_ends_at')
            ->whereBetween('trial_ends_at', [$now, $now->copy()->addDays($days + 1)])
            ->chunkById(100, function (Collection $subscriptions) use ($reminders, $attempts, $now, $days, $dryRun, &$warned): void {
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

                    if (! $reminders->due($owner, $endsAt, $now, $days) || $reminders->sentFor($owner, $endsAt)) {
                        continue;
                    }

                    if ($dryRun) {
                        $warned++;

                        continue;
                    }

                    // The same mark the generic path records, so the two dedupe against each other and the
                    // provider's own notice does too: it writes this record as well. A second type would make
                    // three paths that each believe they are the only one.
                    $sent = $attempts->attempt(static function () use ($reminders, $owner, $endsAt): void {
                        $reminders->send($owner, $endsAt, 'subscription');
                    });

                    if ($sent) {
                        $warned++;
                    }
                }
            });

        return $warned;
    }

    /**
     * Whether this subscription's provider tells the customer their trial is ending.
     *
     * Asked of the driver's declared capability rather than of its name, because the answer is a property
     * of what that driver actually wires: Stripe's mapper produces the event and its provider registers the
     * notice on it. A driver that cannot be resolved — a subscription left behind by a provider the install
     * no longer configures — is treated as announcing nothing, which is the safe direction: a duplicate
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
     * Whether this owner's generic trial reminder would be wrong or redundant.
     *
     * Two independent reasons, and collapsing them into one is wrong in either direction.
     *
     * "Any subscription at all" assumes a provider that sends the trial-will-end event. A local-engine driver
     * sends none: nothing at such a provider knows a trial is running, so it announces nothing.
     *
     * The capability question alone is not enough either. An owner's `trial_ends_at` is written by
     * `Trials::grant()` and cleared by nothing — not by subscribing — so somebody who converts during their
     * generic trial keeps a future date on their own row. Asked only about the provider, they would be mailed
     * "add a payment method before it ends" while already paying, with a mandate on file.
     *
     * So: a live subscription skips them because they converted, whatever the provider; and a subscription
     * under a provider that announces skips them because the webhook is about to say it. A terminal row
     * under a silent provider skips neither — that owner really is on a generic trial that nobody else
     * will mention.
     */
    private function hasSubscription(Model $owner): bool
    {
        return Subscription::model()::query()
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

    private function windowDays(Repository $config): int
    {
        // `is_numeric` rather than `is_string`, and that is not pedantry. The CLI always hands a string, but
        // `Artisan::call(..., ['--days' => 14])` hands an int, and the stricter check discards it — the
        // caller then silently gets the configured default instead of the window they asked for. A setting
        // that is quietly ignored is worse than one that is refused.
        //
        // The card-expiry command carried the stricter check for a while and this comment said so. It no
        // longer does: both commands take the window as a string or as an int, the same way.
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
