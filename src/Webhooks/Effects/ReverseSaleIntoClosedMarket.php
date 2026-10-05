<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks\Effects;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Bus;
use Pushery\Billing\Contracts\CustomerDirectory;
use Pushery\Billing\Contracts\DedupesOnReference;
use Pushery\Billing\Enums\MarketAccess;
use Pushery\Billing\Events\BillingDomainEvent;
use Pushery\Billing\Events\SaleCountryReported;
use Pushery\Billing\Jobs\ReverseClosedMarketSale;
use Pushery\Billing\Marketplace\MarketAllowlist;
use RuntimeException;

/**
 * Undoes a sale the provider taxed in a country `billing.tax_markets` does not open.
 *
 * The allowlist is meant to close before the money moves, and a checkout that is handed the buyer's country does
 * close it there. A hosted checkout cannot be held to that alone: the buyer types the billing address on the
 * provider's page, the provider cannot restrict its country, and without a registration there it computes zero
 * tax and takes the payment. So this is the second line, run on what the provider reports afterwards.
 *
 * This effect decides, and {@see ReverseClosedMarketSale} acts once its run has committed: it ends the subscription
 * first and refunds its payment second, at the provider, where nothing a rollback does can reach.
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

        // Asked here as well as by the job, so a subscription whose row has not arrived yet fails this run, where
        // a retry and an operator find it, rather than a job nobody reads.
        if ($event->subscriptionReference !== null) {
            ReverseClosedMarketSale::scopeOf($event->subscriptionReference);
        }

        Bus::dispatch(new ReverseClosedMarketSale(
            $event->customerReference,
            $country ?? 'unknown',
            $state,
            $event->saleReference,
            $event->subscriptionReference,
            $chargeReference,
            $event->amount,
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
}
