<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks\Effects;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Bus;
use Pushery\Billing\Contracts\ReadsRoutedInvoiceCommission;
use Pushery\Billing\Enums\ChargeType;
use Pushery\Billing\Enums\MerchantChargePurpose;
use Pushery\Billing\Enums\SettlementState;
use Pushery\Billing\Events\RoutedSubscriptionInvoicePaid;
use Pushery\Billing\Exceptions\RoutedCycleUnreadable;
use Pushery\Billing\Jobs\MoveMerchantShareOnConfirmation;
use Pushery\Billing\Jobs\NameTransferOnConfirmation;
use Pushery\Billing\Marketplace\MarketplaceSaleContext;
use Pushery\Billing\Marketplace\RoutedChargeLedger;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\ValueObjects\RoutedInvoiceCommission;

/**
 * Writes the ledger row for a routed subscription cycle — the one sale the money ledger never saw.
 *
 * ## What was missing
 *
 * `RoutedChargeLedger::record()` had a single caller in the package: the one-time hosted lane. A routed
 * SUBSCRIPTION moved real money every cycle and left no row at all, so the sale was invisible to the three
 * things that read that table — the reversal caps, the earnings counter, and the small-business judgment.
 * Each of them answered as though the cycle had not happened, and each answer looked perfectly ordinary.
 *
 * ## Why a subscription cannot be recorded the way a one-time sale is
 *
 * The one-time lane writes its row when the checkout session opens, because at that moment there is exactly
 * one sale with one known amount. A subscription is priced with a RATE — the lane sets
 * `application_fee_percent` and the provider applies it per invoice — so there is no moment at which the
 * figures are known in advance. They exist once per cycle, at the provider.
 *
 * A subscription on the separate-transfer lane has no rate at the provider at all. It carries its merchant and the
 * terms it was sold under in its own metadata, the platform takes each cycle's whole payment, and the reader
 * computes the cycle's split from those terms on what the buyer paid. The row is the same row; the share moves
 * after it.
 *
 * ## The local row decides whether to ask at all
 *
 * The paid-invoice payload says nothing about routing (measured on the pinned version: no `transfer_data`,
 * no account, not even a link to the payment). So this asks the SUBSCRIPTION this package already recorded.
 * An unrouted one stops here, and stopping here is what keeps three provider calls off every payment on
 * every install that routes nothing.
 *
 * ## A read that fails is LOUD, and that is the whole design
 *
 * The alternative to reading was computing the commission ourselves from the rate. It is cheaper and it is
 * the wrong trade: two derivations of one fact agree until one of them changes, and when they part the
 * ledger holds a plausible wrong number that flows into a clawback cap and a tax judgment with nothing
 * going red. A failed read, by contrast, throws — the queue retries it, and a permanent failure surfaces as
 * a failed job rather than as a missing row nobody is looking for.
 *
 * That is why this does NOT return quietly when the provider cannot answer. Returning would hand back
 * exactly the silence the design pays three provider calls to avoid.
 */
final readonly class RecordRoutedSubscriptionCharge
{
    public function __construct(
        private ReadsRoutedInvoiceCommission $commissions,
        private RoutedChargeLedger $ledger,
        /**
         * Where the posture comes from, resolved from the container when nothing was handed in.
         *
         * Optional so the effect constructs as it always did. The cycle's row still states who sold, because the
         * small-business turnover of a creator who supplies the buyer is not the payout.
         */
        private ?MarketplaceSaleContext $sales = null,
    ) {}

    public function __invoke(RoutedSubscriptionInvoicePaid $event): void
    {
        $subscription = Subscription::model()::query()
            ->where('provider_id', $event->subscriptionReference)
            ->first();

        // Nothing local to route to. Either this install does not mirror subscriptions, or the cycle belongs
        // to a plain platform sale — both are ordinary, and both mean no provider call and no row.
        if (! $subscription instanceof Subscription) {
            return;
        }

        $merchant = $subscription->merchant;

        // The platform's own subscription. The sentinel merchant is the single-seller default, so a null
        // relation here is the overwhelming majority of installs rather than a fault.
        if (! $merchant instanceof Model) {
            return;
        }

        $commission = $this->commissions->forInvoice($event->invoiceReference);

        if (! $commission instanceof RoutedInvoiceCommission) {
            throw RoutedCycleUnreadable::forInvoice($event->invoiceReference, $event->subscriptionReference);
        }

        $charge = $this->ledger->record(
            $merchant,
            'stripe',
            // The INVOICE is the reference, one per cycle. A subscription id would collapse every cycle of
            // one subscription onto a single row — `firstOrCreate` would find the first cycle's row for the
            // twelfth cycle's payment and record nothing, with every reversal cap thereafter answering for
            // the wrong month.
            $event->invoiceReference,
            $commission->gross,
            $commission->fee,
            // Derived, because net is what remains. A third figure from the provider would be a third thing
            // that can disagree with the other two.
            $commission->net(),
            // The terms as they stood for THIS cycle. Null when the provider stated none, and null here
            // means "unknown" rather than "no fee" -- a partial clawback refuses on it rather than
            // reconstructing one from today's configuration and clawing an old cycle back at a new rate.
            //
            // On the separate-transfer lane that is the whole terms the subscription was sold under, fixed part and
            // rounding direction included, because a clawback against a fee with a fixed part needs both.
            $commission->policy(),
            // The lane this cycle took, as the subscription was sold under it, rather than today's configuration read
            // back when a refund needs to know.
            $commission->chargeType,
            // Zero, stated rather than left null. The rate is applied to the invoice total with no tax rate
            // separating a net from a gross, and null on this column means "written before this was
            // recorded" -- a description of old rows, which this is not.
            0,
            sellerPosture: ($this->sales ?? Container::getInstance()->make(MarketplaceSaleContext::class))->posture(),
            // A cycle, and this is the one lane where that is genuinely hard to read back: the reference on
            // this row is the invoice, and reaching the subscription from it takes two more hops through an
            // order that only exists where this package writes it.
            purpose: MerchantChargePurpose::Subscription,
            // The payment the cycle was paid with. The row is keyed by the invoice, and a dispute over this cycle
            // will name only the payment, so without it the chargeback could not find the sale it belongs to.
            paymentReference: $commission->paymentReference,
        );

        // SETTLED AS IT IS WRITTEN, because nothing is left to happen to this money. The paid invoice is the
        // payment having succeeded, and a destination charge moves the merchant's share with the payment itself.
        //
        // Left pending, the row was invisible exactly where it was written to be seen: the earnings counter and the
        // small-business monitor count settled rows only, and the confirmation that settles a hosted sale looks its
        // row up by the payment's id, while a cycle is keyed by its invoice. Nothing ever reached it.
        //
        // The transfer is a field of the payment's charge, which this event does not carry, so it is asked for
        // after the webhook's transaction commits. A redelivery settles nothing a second time and asks nothing.
        if ($charge->charge_type === ChargeType::Destination && $this->ledger->settle($charge)) {
            Bus::dispatch(new NameTransferOnConfirmation((int) $charge->id));
        }

        // A SEPARATE TRANSFER IS NOT SETTLED HERE, because nothing has paid the merchant yet: the platform took the
        // cycle's whole payment. The share moves in a second call once the webhook's transaction commits, funded by
        // the charge behind this invoice and made under the sale's own idempotency key, through the same job a
        // confirmed hosted sale on this lane uses. It settles the row with the transfer, or records why it could not
        // where the retry and `billing:doctor` look. A redelivery asks again, and the job finds the row settled.
        if ($charge->charge_type === ChargeType::SeparateTransfer && $charge->settlement_state === SettlementState::Pending) {
            Bus::dispatch(new MoveMerchantShareOnConfirmation((int) $charge->id));
        }
    }
}
