<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Enums\SubscriptionState;
use Pushery\Billing\Support\SubscriptionReconciler;
use Throwable;

/**
 * Reconciles every billable's subscription from the provider onto the local row — the bulk version of
 * the return-reconcile that runs after a hosted checkout. Use it to backfill after a webhook outage, or
 * on demand to be sure the local state matches the provider. It applies each pulled subscription through
 * the SAME plan-sync effect the webhook uses, so its recency guard means a sync can never overwrite a
 * newer webhook state — it only ever moves a stale local row forward.
 *
 * One provider call per billable, so it is a maintenance command, not a hot path. Scope it to one owner
 * with --owner, or let it chunk the whole customer model (only rows that already have a provider
 * reference).
 */
final class SyncSubscriptionsCommand extends Command
{
    protected $signature = 'billing:sync {--owner= : Sync a single owner by primary key} {--chunk=100} {--dry-run}';

    protected $description = 'Reconcile subscriptions from the provider onto the local rows.';

    public function handle(Repository $config, SubscriptionReconciler $reconciler): int
    {
        $model = $config->get('billing.customer.model');

        if (! is_string($model) || ! is_a($model, Model::class, true)) {
            $this->components->warn('billing.customer.model is not configured; nothing to sync.');

            return self::SUCCESS;
        }

        $dryRun = $this->option('dry-run') === true;
        $synced = 0;

        $this->query($model, $config)->chunkById($this->chunk(),
            /** @param Collection<int, Model> $owners */
            function (Collection $owners) use ($reconciler, $dryRun, &$synced): void {
                foreach ($owners as $owner) {
                    if ($dryRun) {
                        $synced++;

                        continue;
                    }

                    try {
                        if ($reconciler->syncFromProvider($owner) instanceof SubscriptionState) {
                            $synced++;
                        }
                    } catch (Throwable $e) {
                        // One owner's provider hiccup must not abort the whole sweep.
                        report($e);
                        $key = $owner->getKey();
                        $this->components->warn('Could not sync owner '.(is_scalar($key) ? (string) $key : '?').": {$e->getMessage()}");
                    }
                }
            });

        $this->components->info(($dryRun ? 'Would sync ' : 'Synced ')."{$synced} owner(s) from the provider.");

        return self::SUCCESS;
    }

    /**
     * The owners to sync: a single --owner, or every row of the customer model that already has a
     * provider reference (an owner with none has no subscription to reconcile).
     *
     * @param  class-string<Model>  $model
     * @return Builder<Model>
     */
    private function query(string $model, Repository $config): Builder
    {
        $column = $config->get('billing.customer.column', 'stripe_id');
        $query = $model::query()->whereNotNull(is_string($column) && $column !== '' ? $column : 'stripe_id');

        $owner = $this->option('owner');

        if (is_string($owner) && $owner !== '') {
            $query->whereKey($owner);
        }

        return $query;
    }

    private function chunk(): int
    {
        $chunk = $this->option('chunk');

        return is_string($chunk) && (int) $chunk > 0 ? (int) $chunk : 100;
    }
}
