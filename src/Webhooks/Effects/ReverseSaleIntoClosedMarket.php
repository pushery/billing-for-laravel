<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks\Effects;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\CustomerDirectory;
use Pushery\Billing\Contracts\DedupesOnReference;
use Pushery\Billing\Contracts\SubscriptionActions;
use Pushery\Billing\Enums\AuditSource;
use Pushery\Billing\Enums\MarketAccess;
use Pushery\Billing\Enums\RefundKind;
use Pushery\Billing\Events\BillingDomainEvent;
use Pushery\Billing\Events\SaleCountryReported;
use Pushery\Billing\Events\SaleIntoClosedMarketReversed;
use Pushery\Billing\Marketplace\MarketAllowlist;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\Support\BillingAdmin;
use Pushery\Billing\Support\BillingEventLog;
use Pushery\Billing\ValueObjects\MerchantScope;
use Pushery\Billing\ValueObjects\RefundResult;
use RuntimeException;

/**
 * Undoes a sale the provider taxed in a country `billing.tax_markets` does not open.
 *
 * The allowlist is meant to close before the money moves, and a checkout that is handed the buyer's country does
 * close it there. A hosted checkout cannot be held to that alone: the buyer types the billing address on the
 * provider's page, the provider cannot restrict its country, and without a registration there it computes zero
 * tax and takes the payment. So this is the second line, run on what the provider reports afterwards.
 *
 * A subscription is ended first and its payment refunded second. The other order leaves a refunded subscription
 * that bills again whenever the refund is the step that succeeds and the cancellation the one that fails.
 *
 * Inert until an operator configures markets, like the allowlist itself. A sale without consideration raises no
 * tax and is left alone, unless it starts a subscription that would charge later. A country the report does not
 * name counts as closed, as it does for the allowlist: once markets are configured, not knowing where a buyer is
 * is no reason to keep the sale.
 */
final readonly class ReverseSaleIntoClosedMarket implements DedupesOnReference
{
    public function __construct(
        private MarketAllowlist $markets,
        private CustomerDirectory $directory,
        private SubscriptionActions $subscriptions,
        private BillingAdmin $admin,
        private BillingEventLog $log,
        private Dispatcher $events,
    ) {}

    public function __invoke(SaleCountryReported $event): void
    {
        if (! $this->markets->isEnforced()) {
            return;
        }

        $country = $event->country === null || $event->country === '' ? null : strtoupper($event->country);
        $state = $country === null ? MarketAccess::Blocked : $this->markets->stateOf($country);

        if ($state->permitsSale()) {
            return;
        }

        $chargeReference = $event->paid && $event->amount->minorUnits > 0 ? $event->chargeReference : null;

        if ($chargeReference === null && $event->subscriptionReference === null) {
            return;
        }

        $owner = $this->directory->ownerForReference($event->customerReference);

        if (! $owner instanceof Model) {
            return; // a customer this app does not own
        }

        if ($event->subscriptionReference !== null) {
            $this->subscriptions->cancelNow($owner, $this->scopeOf($event->subscriptionReference));
        }

        $refund = $chargeReference === null ? null : $this->admin->refund(
            $owner,
            $chargeReference,
            $event->amount,
            reason: 'The provider taxed this sale in ['.($country ?? 'unknown').'], a market that is not open (state: '.$state->value.').',
            idempotencyKey: 'closed-market:'.$chargeReference,
            kind: RefundKind::ClosedMarket,
        );

        $this->log->record('market.sale_reversed', $owner, [
            'country' => $country ?? 'unknown',
            'state' => $state->value,
            'sale' => $event->saleReference,
            'subscription' => $event->subscriptionReference,
            'charge' => $chargeReference,
            'refunded' => $refund instanceof RefundResult ? $refund->successful : null,
            'amount' => $refund instanceof RefundResult ? $event->amount->minorUnits : 0,
            'currency' => $event->amount->currency,
        ], AuditSource::Webhook);

        $this->events->dispatch(new SaleIntoClosedMarketReversed(
            $owner,
            $country ?? 'unknown',
            $state,
            $event->saleReference,
            subscriptionEnded: $event->subscriptionReference !== null,
            refund: $refund,
        ));
    }

    /**
     * Once per sale and payment state. An invoice is reported when it is finalized and again when it is paid, and
     * the second report is the one with money to return, so the two must not collapse into one run.
     */
    public function dedupReference(BillingDomainEvent $event): string
    {
        if (! $event instanceof SaleCountryReported) {
            throw new RuntimeException('ReverseSaleIntoClosedMarket only handles SaleCountryReported events.');
        }

        return $event->saleReference.':'.($event->paid ? 'paid' : 'open');
    }

    /**
     * The scope the subscription was sold in, read from the row this package keeps for it.
     *
     * Throws while that row is missing instead of assuming the platform scope. The invoice and the subscription
     * arrive as separate deliveries, and ending the owner's subscription in the wrong scope would end one the buyer
     * may keep. The throw retries the job, and by then the subscription delivery has written the row.
     */
    private function scopeOf(string $subscriptionReference): ?MerchantScope
    {
        $subscription = Subscription::query()->where('provider_id', $subscriptionReference)->first();

        if (! $subscription instanceof Subscription) {
            throw new RuntimeException("Subscription [{$subscriptionReference}] has no local row yet, so the scope to end it in is unknown. The job retries.");
        }

        $merchant = $subscription->merchant;

        return $merchant instanceof Model ? MerchantScope::forMerchant($merchant) : null;
    }
}
