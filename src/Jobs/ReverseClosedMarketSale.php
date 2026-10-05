<?php

declare(strict_types=1);

namespace Pushery\Billing\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Config;
use Pushery\Billing\Contracts\CustomerDirectory;
use Pushery\Billing\Contracts\SubscriptionActions;
use Pushery\Billing\Enums\AuditSource;
use Pushery\Billing\Enums\MarketAccess;
use Pushery\Billing\Enums\RefundKind;
use Pushery\Billing\Events\SaleIntoClosedMarketReversed;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\Support\BillingAdmin;
use Pushery\Billing\Support\BillingEventLog;
use Pushery\Billing\Support\Concerns\BacksOffBetweenAttempts;
use Pushery\Billing\ValueObjects\MerchantScope;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\RefundResult;
use Pushery\Billing\Webhooks\Effects\ReverseSaleIntoClosedMarket;
use RuntimeException;

/**
 * Ends and refunds a sale made into a closed market at the provider, once the run that decided to has committed.
 *
 * {@see ReverseSaleIntoClosedMarket} decides. It runs inside the transaction of its webhook run, and a provider call
 * made in there cannot be taken back: the subscription stayed ended and the money refunded while a failure after
 * them, in the record, the announcement or the run's own bookkeeping, rolled back everything the package had written
 * about them. The retry then refunded a second time.
 *
 * Here every step commits on its own. The refund is asked under a key named after the charge, and the refund path
 * keeps that key on the reversal it opens for a routed sale, so a retry of this job finds that reversal instead of
 * asking for the money again. A sale that was not routed carries the same key to the provider, which collapses the
 * repeat.
 *
 * The owner is looked up again when the job runs, through the same directory the effect asked: a customer this app
 * no longer knows has nothing left to end or refund.
 */
final class ReverseClosedMarketSale implements ShouldQueueAfterCommit
{
    use BacksOffBetweenAttempts;
    use InteractsWithQueue;
    use Queueable;

    /** How often the reversal is retried before the job is marked failed. The webhook setting, shared. */
    public int $tries;

    public function __construct(
        public readonly string $customerReference,
        public readonly string $country,
        public readonly MarketAccess $state,
        public readonly string $saleReference,
        public readonly ?string $subscriptionReference,
        public readonly ?string $chargeReference,
        public readonly Money $amount,
    ) {
        $config = Config::array('billing.webhooks', []);

        $this->tries = is_int($config['tries'] ?? null) ? $config['tries'] : 5;
        $this->onConnection(is_string($config['connection'] ?? null) ? $config['connection'] : null);
        $this->onQueue(is_string($config['queue'] ?? null) ? $config['queue'] : null);
    }

    public function handle(CustomerDirectory $directory, SubscriptionActions $subscriptions, BillingAdmin $admin, BillingEventLog $log, Dispatcher $events): void
    {
        $owner = $directory->ownerForReference($this->customerReference);

        if (! $owner instanceof Model) {
            return;
        }

        // The subscription is ended first and its payment refunded second. The other order leaves a refunded
        // subscription that bills again whenever the refund is the step that succeeds and the cancellation the one
        // that fails.
        if ($this->subscriptionReference !== null) {
            $subscriptions->cancelNow($owner, self::scopeOf($this->subscriptionReference));
        }

        $refund = $this->chargeReference === null ? null : $admin->refund(
            $owner,
            $this->chargeReference,
            $this->amount,
            reason: 'The provider taxed this sale in ['.$this->country.'], a market that is not open (state: '.$this->state->value.').',
            idempotencyKey: 'closed-market:'.$this->chargeReference,
            kind: RefundKind::ClosedMarket,
        );

        $log->record('market.sale_reversed', $owner, [
            'country' => $this->country,
            'state' => $this->state->value,
            'sale' => $this->saleReference,
            'subscription' => $this->subscriptionReference,
            'charge' => $this->chargeReference,
            'refunded' => $refund instanceof RefundResult ? $refund->successful : null,
            'amount' => $refund instanceof RefundResult ? $this->amount->minorUnits : 0,
            'currency' => $this->amount->currency,
        ], AuditSource::Webhook);

        $events->dispatch(new SaleIntoClosedMarketReversed(
            $owner,
            $this->country,
            $this->state,
            $this->saleReference,
            subscriptionEnded: $this->subscriptionReference !== null,
            refund: $refund,
        ));
    }

    /**
     * The scope the subscription was sold in, read from the row this package keeps for it.
     *
     * Throws while that row is missing instead of assuming the platform scope. The invoice and the subscription
     * arrive as separate deliveries, and ending the owner's subscription in the wrong scope would end one the buyer
     * may keep. The effect asks this before it hands the sale over, so a missing row fails and retries the webhook
     * run, and by then the subscription delivery has written it.
     */
    public static function scopeOf(string $subscriptionReference): ?MerchantScope
    {
        $subscription = Subscription::model()::query()->where('provider_id', $subscriptionReference)->first();

        if (! $subscription instanceof Subscription) {
            throw new RuntimeException("Subscription [{$subscriptionReference}] has no local row yet, so the scope to end it in is unknown. The job retries.");
        }

        $merchant = $subscription->merchant;

        return $merchant instanceof Model ? MerchantScope::forMerchant($merchant) : null;
    }
}
