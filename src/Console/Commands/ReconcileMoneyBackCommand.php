<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use Pushery\Billing\Contracts\CustomerDirectory;
use Pushery\Billing\Contracts\ReportsMoneyBackOutcomes;
use Pushery\Billing\Events\RefundFailed;
use Pushery\Billing\Support\BillingManager;
use Pushery\Billing\Support\MoneyBackReconciler;
use Pushery\Billing\Support\WebhookEventLedger;
use Pushery\Billing\Webhooks\WebhookEffectRegistry;

/**
 * Pick up the refunds that failed and the chargebacks a bank reversed where the provider's webhook does not say.
 *
 * Meant to run daily, and the service provider schedules it where the active driver can read them back. A refund that
 * failed or was canceled reaches the application as `RefundFailed`, a chargeback the bank reversed as
 * `ChargebackReversed`, each once: an outcome an earlier run recorded is not reported again.
 *
 * The windows count from when the refund or the chargeback was created. Thirty days covers the time a refund takes to
 * be paid out or to fail. A bank decides on a chargeback within months, so that window is six of them.
 */
final class ReconcileMoneyBackCommand extends Command
{
    protected $signature = 'billing:money-back:reconcile
        {--refund-days=30 : How far back to read refunds, counted from the day each was created}
        {--chargeback-days=180 : How far back to read chargebacks, counted from the day each was created}';

    protected $description = 'Pick up the refunds that failed and the chargebacks a bank reversed where the provider sends no webhook';

    public function handle(BillingManager $manager, Container $container, WebhookEventLedger $deliveries, WebhookEffectRegistry $registry): int
    {
        $refundDays = $this->days('refund-days');
        $chargebackDays = $this->days('chargeback-days');

        if ($refundDays === null || $chargebackDays === null) {
            $this->components->error('--refund-days and --chargeback-days each take a whole number of days, from 1 up.');

            return self::INVALID;
        }

        $driver = $manager->driver()->name();

        // Resolved rather than injected: a driver whose webhook says what became of a refund and a chargeback binds no
        // reader, and the command is then inapplicable rather than unconstructable.
        $outcomes = $container->bound(ReportsMoneyBackOutcomes::class)
            ? $container->make(ReportsMoneyBackOutcomes::class)
            : null;

        if (! $outcomes instanceof ReportsMoneyBackOutcomes) {
            $this->components->info("The {$driver} driver reads no refunds or chargebacks back, so there is nothing to reconcile.");

            return self::SUCCESS;
        }

        $now = CarbonImmutable::now();
        $reconciler = new MoneyBackReconciler($outcomes, $deliveries, $registry, $container->make(CustomerDirectory::class), $driver);
        $dispatched = $reconciler->reconcile($now->subDays($refundDays), $now->subDays($chargebackDays));

        foreach ($dispatched as $outcome) {
            $this->components->twoColumnDetail(
                $outcome instanceof RefundFailed
                    ? 'refund '.$outcome->refundReference.' ('.$outcome->failure->value.')'
                    : 'chargeback '.$outcome->disputeReference.' (reversed)',
                $outcome->amount->toDecimal().' '.$outcome->amount->currency,
            );
        }

        $this->components->info($dispatched === []
            ? "Nothing new across {$refundDays} days of refunds and {$chargebackDays} days of chargebacks."
            : count($dispatched).' outcome(s) recorded and dispatched.');

        return self::SUCCESS;
    }

    /** A window option as whole days from 1 up, or null for anything else. */
    private function days(string $option): ?int
    {
        $value = $this->option($option);

        return is_string($value) && preg_match('/^\d+$/', $value) === 1 && (int) $value >= 1 ? (int) $value : null;
    }
}
