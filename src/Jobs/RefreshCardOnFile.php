<?php

declare(strict_types=1);

namespace Pushery\Billing\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Config;
use Pushery\Billing\Contracts\MirrorsCardOnFile;
use Pushery\Billing\Support\Concerns\BacksOffBetweenAttempts;
use Pushery\Billing\Webhooks\Effects\MirrorDefaultPaymentMethod;

/**
 * Reads the owner's default payment method from the provider and copies it onto the owner, once the run that heard of
 * the change has committed.
 *
 * {@see MirrorDefaultPaymentMethod} hears of the change on a webhook, and an effect runs inside the transaction of its
 * run, where a provider call holds the transaction open. So the effect hands the read over. A second delivery reads the
 * same method a second time, which is harmless.
 */
final class RefreshCardOnFile implements ShouldQueueAfterCommit
{
    use BacksOffBetweenAttempts;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** How often the read is retried before the job is marked failed. The webhook setting, shared. */
    public int $tries;

    /** An owner erased before the job runs has no card left to copy. */
    public bool $deleteWhenMissingModels = true;

    public function __construct(public readonly Model $owner)
    {
        $config = Config::array('billing.webhooks', []);

        $this->tries = is_int($config['tries'] ?? null) ? $config['tries'] : 5;
        $this->onConnection(is_string($config['connection'] ?? null) ? $config['connection'] : null);
        $this->onQueue(is_string($config['queue'] ?? null) ? $config['queue'] : null);
    }

    public function handle(MirrorsCardOnFile $methods): void
    {
        $methods->mirror($this->owner);
    }
}
