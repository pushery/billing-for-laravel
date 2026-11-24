<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Pushery\Billing\Enums\OrderStatus;
use Pushery\Billing\Models\Order;
use Pushery\Billing\Support\BillingManager;
use Pushery\Billing\Support\CyclePaymentReconciler;
use Pushery\Billing\Support\SweepAttempts;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Reads back the payment of every cycle in collection whose webhook has not arrived.
 *
 * Meant to run hourly, where the package's own engine bills the cycles. A cycle charged by direct debit waits in
 * `processing` for the webhook that says whether the money came; one that has waited longer than `--hours` has its
 * payment read back from the provider and recorded where it stands, as its webhook would have been. A paid payment
 * settles the cycle, a failed one fails it and starts the dunning, and one still open stays open. Nothing is charged:
 * the payment exists already, and only its standing is asked for.
 */
final class ReconcileCyclePaymentsCommand extends Command
{
    protected $signature = 'billing:cycles:reconcile
        {--hours=3 : How many hours a cycle waits in collection before its payment is read back}';

    protected $description = 'Read back the payment of every cycle in collection whose webhook has not arrived';

    public function handle(CyclePaymentReconciler $reconciler, BillingManager $manager): int
    {
        $hours = $this->option('hours');

        if (! is_string($hours) || preg_match('/^\d+$/D', $hours) !== 1 || (int) $hours < 1) {
            $this->components->error('--hours takes a whole number of hours, from 1 up.');

            return self::INVALID;
        }

        $read = 0;
        $dispatched = 0;
        $attempts = new SweepAttempts;

        // `updated_at` is when the order last moved, so a cycle whose charge went out is read once it has waited that
        // long, and the reference is the payment the charge made.
        Order::model()::query()
            ->where('provider', $manager->driver()->name())
            ->where('status', OrderStatus::Processing)
            ->whereNotNull('payment_reference')
            ->where('updated_at', '<=', CarbonImmutable::now()->subHours((int) $hours))
            ->chunkById(100, function (Collection $orders) use ($reconciler, $attempts, &$read, &$dispatched): void {
                /** @var Collection<int, Order> $orders */
                foreach ($orders as $order) {
                    $read++;

                    // A payment whose events a listener throws on keeps its standing unhandled for the next run, and
                    // holds up no other cycle.
                    $attempts->attempt(static function () use ($reconciler, $order, &$dispatched): void {
                        $dispatched += $reconciler->reconcile((string) $order->payment_reference);
                    });
                }
            });

        $this->components->info(OutputFormatter::escape("{$read} cycle(s) in collection read back, {$dispatched} event(s) recorded and dispatched."));

        $attempts->finish();

        return self::SUCCESS;
    }
}
