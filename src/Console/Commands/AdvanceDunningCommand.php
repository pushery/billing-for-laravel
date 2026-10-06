<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use DateTimeInterface;
use Illuminate\Console\Command;
use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
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
use Throwable;

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
        $failed = 0;

        Subscription::model()::query()->whereNotNull('delinquent_since')->chunkById(100,
            /** @param Collection<int, Subscription> $subscriptions */
            function (Collection $subscriptions) use ($levels, $notifier, $fees, $log, $dryRun, $now, &$advanced, &$failed): void {
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

                    if ($dryRun) {
                        $advanced++;

                        continue;
                    }

                    // One subscription a provider refuses for good, a customer deleted there for instance, must not stop
                    // the ladder for every subscription after it. It is reported and tried again on the next run, where
                    // its rung has still not been recorded.
                    try {
                        $this->advance($subscription, $owner, $next, $notifier, $fees, $log);
                        $advanced++;
                    } catch (Throwable $e) {
                        $failed++;
                        Container::getInstance()->make(ExceptionHandler::class)->report($e);
                        $this->components->warn("Could not advance subscription {$subscription->id}: {$e->getMessage()}");
                    }
                }
            });

        $this->components->info(($dryRun ? 'Would advance ' : 'Advanced ')."{$advanced} delinquent owner(s) up the dunning ladder.");

        if ($failed > 0) {
            $this->components->error("{$failed} subscription(s) could not be advanced; the next run tries them again.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Climb one rung: the fee first, then the warning that names it, then the rung itself.
     *
     * The fee goes first because it is the step a provider can refuse for good. Refused, it stops the rung before
     * the customer is warned about a fee that was never charged, and the next run does not warn them a second
     * time for a rung they have not reached. The fee's reference keeps a retry from charging it twice.
     */
    private function advance(Subscription $subscription, Model $owner, DunningLevel $next, SuspensionNotifier $notifier, LateFees $fees, BillingEventLog $log): void
    {
        if ($next->hasFee()) {
            $fees->apply($owner, $next->fee, $this->feeReference($subscription, $next), "Late fee ({$next->label})", $subscription);
        }

        $notifier->suspensionWarning($owner, $next->fee);

        $subscription->forceFill(['dunning_level' => $next->position])->save();

        $log->record('dunning.advanced', $owner, payload: [
            'level' => $next->position,
            'label' => $next->label,
            'fee' => $next->fee->minorUnits,
        ], source: AuditSource::System);
    }

    /**
     * The fee's idempotency key: the subscription, the delinquency the rung belongs to, and the rung.
     *
     * The delinquency is in it because a returning customer can be given the same subscription row again. Without it
     * the first rung of a second delinquency carries the key of the first one's, and a driver takes the fee for a
     * retry and raises nothing, while the warning still names it. Within one delinquency the key stays the same from
     * run to run, which is what keeps a retry from charging the fee twice.
     */
    private function feeReference(Subscription $subscription, DunningLevel $rung): string
    {
        return "dunning:{$subscription->id}:{$subscription->delinquent_since?->getTimestamp()}:{$rung->position}";
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
