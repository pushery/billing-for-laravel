<?php

declare(strict_types=1);

namespace Pushery\Billing\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Config;
use Pushery\Billing\Contracts\MovesMerchantShare;
use Pushery\Billing\Contracts\ReversesMerchantShare;
use Pushery\Billing\Enums\RefundAttemptStatus;
use Pushery\Billing\Marketplace\RoutedChargeLedger;
use Pushery\Billing\Models\RefundAttempt;
use Pushery\Billing\ValueObjects\Money;
use Throwable;

/**
 * Takes the merchant's share back at the provider after a lost dispute, OUTSIDE the transaction that
 * decided to.
 *
 * ## Why this is a job and not part of the effect
 *
 * Every webhook effect runs inside `HandleWebhookEffect`'s `DB::transaction`. That is deliberate and right:
 * it makes the dedup claim roll back with the effect, so a failure leaves work that can be re-claimed rather
 * than a marker for work nobody did.
 *
 * It also makes "call the provider outside the transaction" IMPOSSIBLE for an ordinary effect. An effect
 * opening its own transaction gets a SAVEPOINT, not a commit — so a nested `DB::transaction` reads like the
 * promise and is not one. There is an existing class in this package whose docblock makes exactly that
 * promise on exactly that path, which is why the shape here is different rather than modeled on it.
 *
 * So the effect writes the intent and this job spends it. `ShouldQueueAfterCommit` is the seam: the job is
 * only enqueued once the transaction that claimed the work has actually committed, so a rolled-back
 * chargeback cannot leave a reversal in flight against money nobody took back.
 *
 * ## Why it carries an id and not the attempt
 *
 * A serialized model is a snapshot, and this one is about to be written to by whatever else the provider is
 * telling us. Re-reading it here means the amounts and the status come from the row as it is now, and a
 * retry after a partial failure sees what the first run left behind rather than what it started from.
 */
final class ReverseMerchantShareForChargeback implements ShouldQueueAfterCommit
{
    use InteractsWithQueue;
    use Queueable;

    /** How often the reversal is retried before the job is marked failed. The webhook setting, shared. */
    public int $tries;

    public function __construct(
        public readonly int $attemptId,
        public readonly string $transferReference,
    ) {
        $config = Config::array('billing.webhooks', []);

        $this->tries = is_int($config['tries'] ?? null) ? $config['tries'] : 5;
        $this->onConnection(is_string($config['connection'] ?? null) ? $config['connection'] : null);
        $this->onQueue(is_string($config['queue'] ?? null) ? $config['queue'] : null);
    }

    public function handle(Container $container, RoutedChargeLedger $ledger): void
    {
        $attempt = RefundAttempt::query()->find($this->attemptId);

        // Gone means somebody removed it deliberately, and a reversal for an intent that no longer exists is
        // money moved on nobody's authority. Nothing to do and nothing to record.
        if (! $attempt instanceof RefundAttempt) {
            return;
        }

        // Already decided. A retry after the provider answered but before this row was written would
        // otherwise reverse a second time -- the provider's idempotency key protects against that, and this
        // is the local half of the same promise.
        if ($attempt->status !== RefundAttemptStatus::Pending) {
            return;
        }

        // Resolved from the container the way every other optional marketplace capability is, and NOT from
        // the driver: `StripeMerchantTransfers` implements both verbs and is bound under the outbound one,
        // so asking the driver would answer about the wrong object. An install that binds no transfers at
        // all -- a destination-charge install, where the provider unwinds the transfer with the refund --
        // legitimately has nothing here.
        $transfers = $container->bound(MovesMerchantShare::class)
            ? $container->make(MovesMerchantShare::class)
            : null;

        if (! $transfers instanceof ReversesMerchantShare) {
            // Recorded as a failure rather than swallowed. A driver that cannot reverse leaves the merchant
            // holding a share of a sale the buyer took back, and an attempt with no ending is the state in
            // which nobody can later say whether it was tried.
            $ledger->failRefund($attempt, 'The active driver cannot reverse a merchant transfer.');

            return;
        }

        try {
            $transfers->reverseShare(
                $this->transferReference,
                new Money($attempt->transfer_reversal_minor, $attempt->currency),
                // The attempt's own key, written before this job existed. A key derived from the amount
                // would change the moment a partial reversal moved it, and a changed key is a second
                // reversal at the provider.
                $attempt->idempotency_key,
            );
        } catch (Throwable $e) {
            $ledger->failRefund($attempt, $e->getMessage());

            throw $e;
        }

        $ledger->completeRefund($attempt);
    }
}
