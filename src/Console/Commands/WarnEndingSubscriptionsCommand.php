<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use DateTimeInterface;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Pushery\Billing\Contracts\MerchantDisplayName;
use Pushery\Billing\Contracts\SubscriptionEndingNotifier;
use Pushery\Billing\Models\BillingEvent;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\Support\BillingEventLog;
use Pushery\Billing\ValueObjects\MerchantScope;

/**
 * Reminds owners whose canceled subscription is about to end.
 *
 * ## The reminder a cancellation was missing
 *
 * An owner who cancels keeps the plan until the end of the period they paid for and loses it on that day. The
 * cancellation notice goes out when they cancel, often weeks earlier, and nothing told them again while resuming
 * would still keep the plan without a gap. The trial and the expiring card each had that reminder; this is the same
 * reminder for the period a cancellation leaves.
 *
 * ## Once per end, not once per owner
 *
 * The mark is written to the audit log against the owner, naming the subscription and the day it ends. An owner who
 * resumes and cancels again ends on another day and is reminded again, and the same day is never reminded twice. The
 * mark is recorded only when something was sent, so a dry run does not silence the real run behind it.
 *
 * ## Every subscription, the merchants' included
 *
 * A subscription a buyer holds with a merchant ends like the platform's own, so it is reminded the same way, under
 * the merchant's name, and its mark is its own: the platform's reminder does not silence it, nor it the platform's.
 *
 * Meant to run daily.
 */
final class WarnEndingSubscriptionsCommand extends Command
{
    protected $signature = 'billing:subscriptions:warn {--days= : Remind about canceled subscriptions ending within this many days} {--dry-run}';

    protected $description = 'Remind owners whose canceled subscription is about to end.';

    public function handle(Repository $config, SubscriptionEndingNotifier $notifier, BillingEventLog $log, MerchantDisplayName $merchants): int
    {
        $days = $this->windowDays($config);
        $dryRun = $this->option('dry-run') === true;
        $warned = 0;

        // From now, not from the start of the day: a subscription that already ended is not ending soon, and a
        // reminder about it would offer to keep something already gone.
        $now = Carbon::now();

        Subscription::model()::query()
            ->whereNotNull('ends_at')
            ->whereBetween('ends_at', [$now, $now->copy()->addDays($days)])
            ->with(['owner', 'merchant'])
            ->chunkById(100, function (Collection $subscriptions) use ($notifier, $log, $merchants, $dryRun, &$warned): void {
                /** @var Collection<int, Subscription> $subscriptions */
                foreach ($subscriptions as $subscription) {
                    $endsAt = $subscription->ends_at;
                    $owner = $subscription->getRelationValue('owner');

                    if (! $endsAt instanceof DateTimeInterface || ! $owner instanceof Model || ! $subscription->onGracePeriod()) {
                        continue;
                    }

                    $endsAt = Carbon::instance($endsAt);

                    if ($this->alreadyWarned($owner, $subscription, $endsAt)) {
                        continue;
                    }

                    $warned++;

                    if ($dryRun) {
                        continue;
                    }

                    $merchant = $this->merchantOf($subscription);

                    $notifier->subscriptionEnding($owner, $endsAt, $merchant, $merchant instanceof MerchantScope ? $this->merchantName($subscription, $merchants) : null);

                    $log->record('subscription.ending_notice_sent', $owner, [
                        'subscription' => $this->subscriptionKey($subscription),
                        'merchant' => $subscription->merchant_uid,
                        'ends_at' => $endsAt->format('Y-m-d'),
                    ]);
                }
            });

        $this->components->info(($dryRun ? 'Would remind ' : 'Reminded ')."{$warned} owner(s) of a subscription about to end.");

        return self::SUCCESS;
    }

    /** The merchant a subscription is held with, or null for the platform's own. */
    private function merchantOf(Subscription $subscription): ?MerchantScope
    {
        $scope = new MerchantScope($subscription->merchant_type, $subscription->merchant_id);

        return $scope->isPlatform() ? null : $scope;
    }

    /** The name of the merchant a subscription is held with, or null for a merchant without one. */
    private function merchantName(Subscription $subscription, MerchantDisplayName $merchants): ?string
    {
        $merchant = $subscription->getRelationValue('merchant');

        if (! $merchant instanceof Model) {
            return null;
        }

        $name = $merchants->nameFor($merchant);

        return is_string($name) && trim($name) !== '' ? $name : null;
    }

    /** Whether this owner has already been reminded of this subscription ending on this day. */
    private function alreadyWarned(Model $owner, Subscription $subscription, Carbon $endsAt): bool
    {
        // Compared in PHP rather than through a JSON path in SQL, for the reason the trial reminder gives: the engines
        // spell a JSON path differently, and a dedupe that matched nothing on one of them would mail every night.
        $key = $this->subscriptionKey($subscription);
        $date = $endsAt->format('Y-m-d');

        return BillingEvent::model()::query()
            ->where('type', 'subscription.ending_notice_sent')
            ->where('subject_type', $owner->getMorphClass())
            ->where('subject_id', $owner->getKey())
            ->get()
            ->contains(static fn (BillingEvent $event): bool => ($event->payload['subscription'] ?? null) === $key
                && ($event->payload['ends_at'] ?? null) === $date);
    }

    /** The subscription's key as the notice records it and the reminder compares it, in one spelling for both. */
    private function subscriptionKey(Subscription $subscription): string
    {
        $key = $subscription->getKey();

        return is_int($key) || is_string($key) ? (string) $key : '';
    }

    private function windowDays(Repository $config): int
    {
        // `is_numeric`, not `is_string`: `Artisan::call(..., ['--days' => 7])` hands an int, and the stricter check
        // would quietly answer with the configured window instead of the one asked for.
        $option = $this->option('days');

        if (is_numeric($option) && (int) $option > 0) {
            return (int) $option;
        }

        $configured = $config->get('billing.subscriptions.warn_ending_within_days', 3);

        return is_int($configured) && $configured > 0 ? $configured : 3;
    }
}
