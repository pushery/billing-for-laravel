<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification;
use Pushery\Billing\Contracts\PaymentMethods;
use Pushery\Billing\Notifications\CardExpiringNotification;
use Pushery\Billing\ValueObjects\PaymentMethod;

/**
 * Warns owners whose default card is about to expire — the biggest preventable cause of involuntary
 * churn. Scans the configured customer model (only rows that already have a provider customer reference),
 * reads each owner's default method, and notifies once per run for a card expiring inside the window. It
 * makes one provider call per owner, so it is meant to run on a daily schedule, not per request.
 */
final class WarnExpiringCardsCommand extends Command
{
    protected $signature = 'billing:cards:warn {--days= : Warn about cards expiring within this many days} {--dry-run}';

    protected $description = 'Notify owners whose default card is about to expire.';

    public function handle(Repository $config, PaymentMethods $methods): int
    {
        $model = $config->get('billing.customer.model');

        if (! is_string($model) || ! is_a($model, Model::class, true)) {
            $this->components->warn('billing.customer.model is not configured; nothing to scan.');

            return self::SUCCESS;
        }

        $days = $this->windowDays($config);
        $column = $this->column($config);
        $dryRun = $this->option('dry-run') === true;
        $warned = 0;

        $model::query()->whereNotNull($column)->chunkById(100,
            /** @param Collection<int, Model> $owners */
            function (Collection $owners) use ($methods, $days, $dryRun, &$warned): void {
                foreach ($owners as $owner) {
                    $method = $methods->default($owner);
                    if (! $method instanceof PaymentMethod) {
                        continue;
                    }
                    if (! $method->isExpiringWithin($days)) {
                        continue;
                    }

                    $warned++;

                    if (! $dryRun) {
                        Notification::send($owner, new CardExpiringNotification($method));
                    }
                }
            });

        $this->components->info(($dryRun ? 'Would warn ' : 'Warned ')."{$warned} owner(s) about an expiring card.");

        return self::SUCCESS;
    }

    private function windowDays(Repository $config): int
    {
        // `is_numeric` rather than `is_string`, and it is the same line the trial command already carries —
        // whose comment names THIS command as the one that lacked it. The command line always hands a
        // string; `Artisan::call(..., ['--days' => 60])` hands an int, and the stricter check discarded it
        // and returned the configured default instead. A setting that is quietly ignored is worse than one
        // that is refused: the caller gets a plausible result for a window they did not ask for.
        //
        // Still `> 0` after the cast, so a non-numeric value falls back to the configured window rather than
        // through as a zero — which would warn nobody, silently, which is this same defect wearing different
        // clothes.
        $option = $this->option('days');

        if (is_numeric($option) && (int) $option > 0) {
            return (int) $option;
        }

        $configured = $config->get('billing.cards.warn_within_days', 30);

        return is_int($configured) && $configured > 0 ? $configured : 30;
    }

    private function column(Repository $config): string
    {
        $column = $config->get('billing.customer.column', 'stripe_id');

        return is_string($column) && $column !== '' ? $column : 'stripe_id';
    }
}
