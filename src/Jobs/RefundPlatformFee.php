<?php

declare(strict_types=1);

namespace Pushery\Billing\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Config;
use Pushery\Billing\Contracts\RefundsPlatformFee;
use Pushery\Billing\Models\RefundAttempt;
use Pushery\Billing\Support\BillingManager;
use Pushery\Billing\Support\Concerns\BacksOffBetweenAttempts;
use Pushery\Billing\ValueObjects\Money;

/**
 * Gives back the platform's fee on a refunded destination payment, in the amount the ledger booked for the refund.
 *
 * ## Why the fee is not left to the refund
 *
 * A provider asked to share the fee out with a refund gives back a share of the fee as it was first withheld, and the
 * ledger books the fee recomputed on what remains of the sale. They differ where the fee has a fixed part, which a
 * half refund does not halve, and where the fee was corrected after the payment. So the refund is made without the
 * fee, and this gives back the ledger's figure, which `RoutedChargeLedger::completeRefund()` has already booked.
 *
 * ## Why a job
 *
 * The buyer's refund has happened by the time this runs, and it must not be asked for again because the fee could not
 * be given back. A job retries the fee alone, with the waits every provider job here uses, under the refund's own key,
 * which the driver finds on a fee refund it already made. One that fails for good is a failed job, and the merchant's
 * missing fee shows in `billing:merchants:reconcile` until somebody gives it back.
 */
final class RefundPlatformFee implements ShouldQueueAfterCommit
{
    use BacksOffBetweenAttempts;
    use InteractsWithQueue;
    use Queueable;

    /** How often the refund is retried before the job is marked failed. The webhook setting, shared. */
    public int $tries;

    public function __construct(
        public readonly int $attemptId,
        /** The fee the ledger booked as given back with the refund, in the attempt's currency. */
        public readonly int $feeMinor,
    ) {
        $config = Config::array('billing.webhooks', []);

        $this->tries = is_int($config['tries'] ?? null) ? $config['tries'] : 5;
        $this->onConnection(is_string($config['connection'] ?? null) ? $config['connection'] : null);
        $this->onQueue(is_string($config['queue'] ?? null) ? $config['queue'] : null);
    }

    public function handle(BillingManager $manager): void
    {
        $attempt = RefundAttempt::model()::query()->find($this->attemptId);

        if (! $attempt instanceof RefundAttempt || $this->feeMinor <= 0) {
            return;
        }

        // The rails of the provider that made the payment, which made the refund as well. A driver whose rails cannot
        // give a fee back separately never had the refund made without it.
        $rails = $manager->driver($attempt->provider)->rails();

        if (! $rails instanceof RefundsPlatformFee) {
            return;
        }

        $rails->refundPlatformFee($attempt->charge_reference, new Money($this->feeMinor, $attempt->currency), $attempt->idempotency_key);
    }
}
