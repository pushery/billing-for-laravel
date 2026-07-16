<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use DateTimeInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Pushery\Billing\Contracts\LateFees;
use Pushery\Billing\Contracts\SuspensionNotifier;
use Pushery\Billing\Dunning\ConfigDunningLadder;
use Pushery\Billing\Enums\AuditSource;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\Support\BillingEventLog;
use Pushery\Billing\ValueObjects\DunningLevel;

/**
 * Walks the dunning ladder for every delinquent owner: each run advances a subscription by AT MOST ONE
 * rung, and only when that next rung's day has been reached (measured from the outage-safe
 * delinquent_since clock). Advancing sends the escalating suspension warning once, charges the rung's
 * configured fee once (via the LateFees seam), and records the rung reached — so the package finally
 * escalates instead of sending one notice on day zero and then falling silent until a surface 423s with
 * no warning. Meant to run daily; the one-rung-per-run rule keeps a lagged run from firing a burst of
 * warnings, and dunning_level (reset to 0 on recovery by the plan-sync effect) makes every rung fire
 * exactly once.
 */
final class AdvanceDunningCommand extends Command
{
    protected $signature = 'billing:dunning:advance {--dry-run}';

    protected $description = 'Advance the dunning ladder for delinquent owners (send the next warning, charge its fee).';

    public function handle(ConfigDunningLadder $ladder, SuspensionNotifier $notifier, LateFees $fees, BillingEventLog $log): int
    {
        $levels = $ladder->levels();
        $dryRun = $this->option('dry-run') === true;
        $now = Carbon::now();
        $advanced = 0;

        Subscription::query()->whereNotNull('delinquent_since')->chunkById(100,
            /** @param Collection<int, Subscription> $subscriptions */
            function (Collection $subscriptions) use ($levels, $notifier, $fees, $log, $dryRun, $now, &$advanced): void {
                foreach ($subscriptions as $subscription) {
                    $next = $levels[$subscription->dunning_level] ?? null;
                    // No further rung to climb (already at the top), or its day has not arrived yet.
                    if (! $next instanceof DunningLevel) {
                        continue;
                    }
                    if (! $this->reached($next, $subscription->delinquent_since, $now)) {
                        continue;
                    }

                    $owner = $this->ownerOf($subscription);

                    if (! $owner instanceof Model) {
                        continue;
                    }

                    $advanced++;

                    if ($dryRun) {
                        continue;
                    }

                    $notifier->suspensionWarning($owner, $next->fee);

                    if ($next->hasFee()) {
                        $fees->apply($owner, $next->fee, "dunning:{$subscription->id}:{$next->position}", "Late fee ({$next->label})");
                    }

                    $subscription->forceFill(['dunning_level' => $next->position])->save();

                    $log->record('dunning.advanced', $owner, payload: [
                        'level' => $next->position,
                        'label' => $next->label,
                        'fee' => $next->fee->minorUnits,
                    ], source: AuditSource::System);
                }
            });

        $this->components->info(($dryRun ? 'Would advance ' : 'Advanced ')."{$advanced} delinquent owner(s) up the dunning ladder.");

        return self::SUCCESS;
    }

    /**
     * Whether the next rung's day has arrived. The `$since !== null` narrows the nullable column for the
     * value object (the query already guarantees a non-null delinquency clock, so this only ever short-
     * circuits in theory), and isReachedAt does the day comparison.
     */
    private function reached(DunningLevel $next, ?DateTimeInterface $since, DateTimeInterface $now): bool
    {
        return $since instanceof DateTimeInterface && $next->isReachedAt($since, $now);
    }

    /** Resolve the subscription's owner back to a model instance via the morph map. */
    private function ownerOf(Subscription $subscription): ?Model
    {
        $class = Relation::getMorphedModel($subscription->owner_type) ?? $subscription->owner_type;

        if (! is_subclass_of($class, Model::class)) {
            return null;
        }

        $owner = $class::query()->find($subscription->owner_id);

        return $owner instanceof Model ? $owner : null;
    }
}
