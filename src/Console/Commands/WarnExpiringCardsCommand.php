<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification;
use Pushery\Billing\Contracts\PaymentMethods;
use Pushery\Billing\Models\BillingEvent;
use Pushery\Billing\Notifications\CardExpiringNotification;
use Pushery\Billing\Support\BillingEventLog;
use Pushery\Billing\ValueObjects\PaymentMethod;
use Throwable;

/**
 * Warns owners whose default card is about to expire — the biggest preventable cause of involuntary
 * churn. Scans the configured customer model (only rows that already have a provider customer reference),
 * reads each owner's default method, and notifies once per card and expiry for a card expiring inside the window.
 * It makes one provider call per owner, so it is meant to run on a daily schedule, not per request.
 */
final class WarnExpiringCardsCommand extends Command
{
    protected $signature = 'billing:cards:warn {--days= : Warn about cards expiring within this many days} {--dry-run}';

    protected $description = 'Notify owners whose default card is about to expire.';

    public function handle(Repository $config, PaymentMethods $methods, BillingEventLog $log): int
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
        $failed = 0;

        $model::query()->whereNotNull($column)->chunkById(100,
            /** @param Collection<int, Model> $owners */
            function (Collection $owners) use ($methods, $log, $days, $dryRun, &$warned, &$failed): void {
                foreach ($owners as $owner) {
                    // One owner the provider cannot answer for, a customer deleted there for instance, must not stop
                    // the warnings for every owner after them. It is reported and asked about again on the next run.
                    try {
                        $warned += $this->warnIfExpiring($owner, $methods, $log, $days, $dryRun) ? 1 : 0;
                    } catch (Throwable $e) {
                        $failed++;
                        Container::getInstance()->make(ExceptionHandler::class)->report($e);
                        $key = $owner->getKey();
                        $this->components->warn('Could not warn owner '.(is_scalar($key) ? (string) $key : '?').": {$e->getMessage()}");
                    }
                }
            });

        $this->components->info(($dryRun ? 'Would warn ' : 'Warned ')."{$warned} owner(s) about an expiring card.");

        if ($failed > 0) {
            $this->components->error("{$failed} owner(s) could not be checked; the next run asks again.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** Warn one owner whose default card runs out within the window, once per card and expiry; whether a warning went out. */
    private function warnIfExpiring(Model $owner, PaymentMethods $methods, BillingEventLog $log, int $days, bool $dryRun): bool
    {
        $method = $methods->default($owner);

        if (! $method instanceof PaymentMethod || ! $method->isExpiringWithin($days) || $this->alreadyWarned($owner, $method)) {
            return false;
        }

        // The mark is written only when something was sent: a dry run that wrote it would silence the real run
        // behind it.
        if ($dryRun) {
            return true;
        }

        Notification::send($owner, new CardExpiringNotification($method));

        $log->record('card.expiring_notice_sent', $owner, [
            'method' => $method->id,
            'expires' => $this->expiry($method),
        ]);

        return true;
    }

    /**
     * Whether this card's expiry was already announced to this owner.
     *
     * Once per card and expiry, from the mark a send leaves in the audit log. The window is true on every day of
     * the month before a card runs out, so a daily run without the mark mailed the same warning thirty times. A
     * card the provider renews with a new expiry is warned about again when that one comes near. Compared in PHP,
     * like the trial reminders, because the three engines spell a JSON path differently.
     */
    private function alreadyWarned(Model $owner, PaymentMethod $method): bool
    {
        $expires = $this->expiry($method);

        return BillingEvent::model()::query()
            ->where('type', 'card.expiring_notice_sent')
            ->where('subject_type', $owner->getMorphClass())
            ->where('subject_id', $owner->getKey())
            ->get()
            ->contains(static fn (BillingEvent $event): bool => ($event->payload['method'] ?? null) === $method->id && ($event->payload['expires'] ?? null) === $expires);
    }

    /** The month the card runs out, as the mark records it. */
    private function expiry(PaymentMethod $method): string
    {
        return sprintf('%04d-%02d', $method->expYear ?? 0, $method->expMonth ?? 0);
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
