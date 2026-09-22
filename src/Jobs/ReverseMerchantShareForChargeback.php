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
        $attempt = RefundAttempt::model()::query()->find($this->attemptId);

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
        //
        // THE REVERSAL CONTRACT IS ASKED FOR FIRST, AND UNTIL 2026-09-19 IT WAS NEVER ASKED AT ALL. This
        // block resolved the OUTBOUND verb and settled the question with an `instanceof` -- so a consumer
        // who followed the contract's own docblock, implemented the reversal in its own class and bound it
        // here, was never reached. Measured across the package on 2026-09-19: one consumer of the contract
        // (this job), ZERO `make()` calls for it, ZERO bindings. The shipped driver satisfies the unwritten
        // condition by accident, because `StripeMerchantTransfers` carries both verbs on one class.
        //
        // What it cost, reported from a consumer: their outbound driver is a wallet of their own, so every
        // lost dispute landed in `failRefund` and the creator kept the share of a sale the network had
        // already clawed back. No throw, no red line, nothing that looks like a defect -- and the message
        // below read as "this driver cannot", when the truth was "yours was never asked".
        //
        // The fallback keeps every existing install unchanged: where nothing is bound under the reversal
        // contract, the outbound object answers exactly as before.
        // THE MESSAGE BELOW ASKS THE CONTAINER, NOT THE RESOLVED OBJECT, AND THAT IS NOT A DETAIL. A
        // `$outbound === null` there reads naturally and the analyzer rejects it as always-true: this
        // package ships ONE implementation of the outbound contract and it happens to carry both verbs, so
        // from a type's point of view an outbound object that cannot reverse does not exist here. It exists
        // in a consumer, which is the whole subject. `bound()` returns a bool and answers the question the
        // message is actually about -- is a transfers driver installed at all.
        // THE "NOTHING IS BOUND" CASE IS DECIDED BEFORE ANYTHING IS RESOLVED, and that ordering is what
        // makes it expressible at all. Asked afterwards -- `$outbound === null`, or a ternary on the
        // binding -- the analyzer rejects it as unreachable, and it is right about the code it can see:
        // this package ships ONE implementation of the outbound contract and that class carries both
        // verbs, so a bound-but-incapable driver does not exist inside these files. It exists in a
        // consumer, which is the entire subject of this contract. Asking the CONTAINER first keeps the two
        // states apart where the type system has nothing to say about them.
        $outboundIsBound = $container->bound(MovesMerchantShare::class);

        if (! $outboundIsBound && ! $container->bound(ReversesMerchantShare::class)) {
            $ledger->failRefund($attempt, 'No merchant-transfer driver is bound, so there is no share to reverse.');

            return;
        }

        $reversals = $container->bound(ReversesMerchantShare::class)
            ? $container->make(ReversesMerchantShare::class)
            : null;

        $outbound = $outboundIsBound ? $container->make(MovesMerchantShare::class) : null;

        $transfers = match (true) {
            $reversals instanceof ReversesMerchantShare => $reversals,
            $outbound instanceof ReversesMerchantShare => $outbound,
            default => null,
        };

        if (! $transfers instanceof ReversesMerchantShare) {
            // Recorded as a failure rather than swallowed. A driver that cannot reverse leaves the merchant
            // holding a share of a sale the buyer took back, and an attempt with no ending is the state in
            // which nobody can later say whether it was tried.
            //
            // TWO MESSAGES, BECAUSE THE TWO STATES NEED DIFFERENT ACTIONS. "Nothing is bound" is a
            // destination-charge install and usually correct; "bound, and it cannot reverse" is a
            // capability gap somebody has to close. One sentence covering both is the sentence nobody
            // acts on.
            $ledger->failRefund(
                $attempt,
                'The bound merchant-transfer driver cannot reverse a share, and nothing is bound under '
                    .ReversesMerchantShare::class.' beside it.',
            );

            return;
        }

        try {
            $reversal = $transfers->reverseShare(
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

        // The provider's own figure, not the one this job asked for. The answer was discarded here for as
        // long as the job existed, so the ledger recorded the REQUEST — and a ledger that records its own
        // request agrees with itself no matter what the provider did.
        $ledger->completeRefund($attempt, $reversal->reversed);
    }
}
