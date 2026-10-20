<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks\Effects;

use Illuminate\Support\Facades\Bus;
use Pushery\Billing\Contracts\ReadsProviderComputedTax;
use Pushery\Billing\Enums\ChargeType;
use Pushery\Billing\Events\AddonPurchased;
use Pushery\Billing\Jobs\TakeCommissionOnNet;
use Pushery\Billing\Models\MerchantCharge;
use Pushery\Billing\ValueObjects\ProviderComputedTax;

/**
 * Brings the commission of a routed purchase on the provider's hosted page down to the net, once its tax is known.
 *
 * The fee of a hosted one-off purchase is fixed when the session opens, before the buyer's rate exists, and it is
 * taken on the price. A tax-exclusive price is the net, and the buyer pays the tax on top, so nothing is wrong there.
 * A tax-inclusive price is the gross, and its fee took the commission on the buyer's tax as well. That is the sale this
 * asks for: one whose payment is the price recorded when the session opened while the provider computed tax inside it.
 * The rate comes from the provider, through the same seam the purchase's document reads it from, and the correction
 * itself runs after the commit, because it gives part of the fee back at the provider.
 *
 * Silent on an unrouted purchase, a separate transfer, which takes its commission when the share moves, a sale that
 * charged no tax, one whose price was tax-exclusive or discounted, one already taken on the net, and one whose tax the
 * provider cannot state as one rate.
 */
final readonly class TakeHostedPurchaseCommissionOnNet
{
    public function __construct(private ReadsProviderComputedTax $taxes) {}

    public function __invoke(AddonPurchased $event): void
    {
        if ($event->provider === null || $event->paymentReference === null || ($event->taxMinor ?? 0) <= 0) {
            return;
        }

        $charge = MerchantCharge::model()::query()
            ->where('provider', $event->provider)
            ->where('charge_reference', $event->paymentReference)
            ->first();

        if (! $charge instanceof MerchantCharge || $charge->charge_type !== ChargeType::Destination || ($charge->commission_tax_bps ?? 0) !== 0) {
            return;
        }

        // The tax was inside the recorded price only where the buyer paid exactly that price. A payment above it is a
        // tax-exclusive price with the tax added on top, whose fee was taken on the net already.
        if ($charge->gross_minor !== $event->amount->minorUnits || $charge->currency !== $event->amount->currency) {
            return;
        }

        $tax = $this->taxes->forSale($event->reference);

        if (! $tax instanceof ProviderComputedTax || ! $tax->accountsFor($event->amount) || $tax->rateBps <= 0) {
            return;
        }

        Bus::dispatch(new TakeCommissionOnNet((int) $charge->id, $tax->rateBps));
    }
}
