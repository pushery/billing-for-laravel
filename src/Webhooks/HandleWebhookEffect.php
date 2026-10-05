<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks;

use Illuminate\Bus\Queueable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Pushery\Billing\Contracts\DedupesOnReference;
use Pushery\Billing\Events\BillingDomainEvent;
use Pushery\Billing\Support\Concerns\BacksOffBetweenAttempts;
use Pushery\Billing\Support\RedactedError;
use Pushery\Billing\Support\WebhookEffectLedger;
use RuntimeException;
use Throwable;

/**
 * Runs ONE webhook effect, on the queue, exactly once.
 *
 * One job per effect is what buys per-effect isolation: an effect that throws fails its own job and
 * retries on its own, instead of aborting every effect registered after it and 500-ing the provider.
 *
 * THE ORDER INSIDE IS THE WHOLE POINT — claim, run, mark handled, all in ONE transaction:
 *
 *   - If the effect throws, the transaction rolls back and takes the CLAIM with it. The work is
 *     re-claimable, so the queue's retry (and `billing:webhooks:replay`) will do it again. The package
 *     used to record the dedup marker BEFORE running the effect, which is how a payment-failure notice
 *     got lost forever: the marker survived, the mail did not, and nothing would ever send it.
 *   - The failure is then recorded OUTSIDE that rolled-back transaction, so an operator can see the work
 *     the package knows it still owes.
 *   - The package's notifications are queued AFTER COMMIT, so a mail is only ever really sent if the run
 *     it belongs to committed. That is what closes the other half: no duplicate mail from a run that
 *     rolled back.
 *   - For the same reason an effect never calls a provider itself. Inside this transaction its own
 *     transactions are savepoints, so a call made there holds the run open, and a rollback after it keeps
 *     what the provider did while the package forgets it. Such a call goes to a job queued after commit,
 *     or to `DB::afterCommit()`.
 *
 * The dedup key is chosen by the effect (see DedupesOnReference), defaulting to the provider's event id inside
 * the account the delivery came from (see WebhookEffectLedger::referenceFor()).
 */
final class HandleWebhookEffect implements ShouldQueueAfterCommit
{
    use BacksOffBetweenAttempts;
    use InteractsWithQueue;
    use Queueable;

    /** How often a failing effect is retried before the job is marked failed. Configurable per app. */
    public int $tries;

    /**
     * The account the delivery came from, or '' for the platform.
     *
     * Set through forAccount() rather than the constructor: a job queued before it existed is unserialized
     * without it, and a promoted property would then stand uninitialized. Null reads as the platform.
     */
    public ?string $accountReference = null;

    /**
     * @param  class-string  $effectClass
     */
    public function __construct(
        public readonly string $effectClass,
        public readonly BillingDomainEvent $event,
        public readonly string $provider,
        public readonly string $eventId,
        public readonly ?int $deliveryId = null,
    ) {
        $config = Config::array('billing.webhooks', []);

        $this->tries = is_int($config['tries'] ?? null) ? $config['tries'] : 5;
        $this->onConnection(is_string($config['connection'] ?? null) ? $config['connection'] : null);
        $this->onQueue(is_string($config['queue'] ?? null) ? $config['queue'] : null);
    }

    /** The same job, for a delivery that came from this account ('' for the platform). */
    public function forAccount(string $accountReference): self
    {
        $this->accountReference = $accountReference;

        return $this;
    }

    public function handle(WebhookEffectLedger $runs): void
    {
        $effect = Container::getInstance()->make($this->effectClass);

        if (! is_callable($effect)) {
            throw new RuntimeException("Webhook effect [{$this->effectClass}] is not invokable.");
        }

        $reference = $effect instanceof DedupesOnReference
            ? $effect->dedupReference($this->event)
            : $runs->referenceFor($this->provider, $this->eventId, $this->accountReference, $this->effectClass, $this->deliveryId);

        try {
            DB::transaction(function () use ($runs, $effect, $reference): void {
                if (! $runs->claim($this->provider, $reference, $this->effectClass, $this->deliveryId)) {
                    return; // another delivery (or an earlier run) already did this effect's work
                }

                $effect($this->event);

                $runs->markHandled($this->provider, $reference, $this->effectClass);
            });
        } catch (Throwable $e) {
            // The claim rolled back with the effect, so this records the failure fresh — outside the
            // transaction that just died — and then lets the queue retry the job.
            //
            // Redacted, because the run table holds nobody and no erasure reaches it, while the message of a
            // failed insert carries the row it was writing. The full message travels with the rethrow.
            $runs->markFailed($this->provider, $reference, $this->effectClass, RedactedError::of($e), $this->deliveryId);

            throw $e;
        }
    }
}
