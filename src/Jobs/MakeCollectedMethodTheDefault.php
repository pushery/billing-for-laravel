<?php

declare(strict_types=1);

namespace Pushery\Billing\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Config;
use Pushery\Billing\Contracts\AdoptsCollectedPaymentMethod;
use Pushery\Billing\Enums\AuditSource;
use Pushery\Billing\Support\BillingEventLog;
use Pushery\Billing\Support\Concerns\BacksOffBetweenAttempts;
use Pushery\Billing\Webhooks\Effects\AdoptCollectedPaymentMethod;

/**
 * Makes a payment method the customer added the one the provider charges next, once the run that heard of it has
 * committed.
 *
 * {@see AdoptCollectedPaymentMethod} hears of the method on a webhook, and an effect runs inside the transaction of
 * its run, where a provider call holds the transaction open and a rollback cannot take the call back. So the effect
 * hands the call over, and the audit line follows the call here: the record says the default changed only once the
 * provider was asked to change it. A second delivery makes the same method the default a second time, which is
 * harmless.
 */
final class MakeCollectedMethodTheDefault implements ShouldQueueAfterCommit
{
    use BacksOffBetweenAttempts;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** How often the change is retried before the job is marked failed. The webhook setting, shared. */
    public int $tries;

    /** An owner erased before the job runs has no customer left whose default could change. */
    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public readonly Model $owner,
        public readonly string $customerReference,
        public readonly string $collectionReference,
    ) {
        $config = Config::array('billing.webhooks', []);

        $this->tries = is_int($config['tries'] ?? null) ? $config['tries'] : 5;
        $this->onConnection(is_string($config['connection'] ?? null) ? $config['connection'] : null);
        $this->onQueue(is_string($config['queue'] ?? null) ? $config['queue'] : null);
    }

    public function handle(AdoptsCollectedPaymentMethod $methods, BillingEventLog $log): void
    {
        $methods->adopt($this->customerReference, $this->collectionReference);

        $log->record('payment_method.default_changed', $this->owner, [
            'collection' => $this->collectionReference,
        ], AuditSource::Webhook);
    }
}
