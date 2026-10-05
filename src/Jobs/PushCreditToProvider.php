<?php

declare(strict_types=1);

namespace Pushery\Billing\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Config;
use Pushery\Billing\Contracts\CreditSync;
use Pushery\Billing\Support\Concerns\BacksOffBetweenAttempts;
use Pushery\Billing\ValueObjects\Money;

/**
 * Mirrors a change in an owner's credit balance onto the provider, once the change itself has committed.
 *
 * The local ledger is the source of truth and the provider's balance follows it. Following it from inside the
 * transaction that changed it held that transaction, and the balance row it had locked, open across a call to the
 * provider. A webhook run is such a transaction: its effects run inside it, and their own transactions are only
 * savepoints. A run that rolled back after the push kept the provider's change and lost the local one, and the
 * retry pushed again.
 *
 * The reference is the money operation's idempotency key, so a retry of this job moves the provider's balance once.
 * An owner erased before the job runs has no customer left at the provider to move a balance for, and the job is
 * dropped.
 */
final class PushCreditToProvider implements ShouldQueueAfterCommit
{
    use BacksOffBetweenAttempts;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** How often the push is retried before the job is marked failed. The webhook setting, shared. */
    public int $tries;

    /** An owner erased before the push runs has no balance at the provider left to move. */
    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public readonly Model $owner,
        public readonly Money $signedDelta,
        public readonly string $reference,
    ) {
        $config = Config::array('billing.webhooks', []);

        $this->tries = is_int($config['tries'] ?? null) ? $config['tries'] : 5;
        $this->onConnection(is_string($config['connection'] ?? null) ? $config['connection'] : null);
        $this->onQueue(is_string($config['queue'] ?? null) ? $config['queue'] : null);
    }

    public function handle(CreditSync $creditSync): void
    {
        $creditSync->push($this->owner, $this->signedDelta, $this->reference);
    }
}
