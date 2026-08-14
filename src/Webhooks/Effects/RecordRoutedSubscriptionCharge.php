<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks\Effects;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\ReadsRoutedInvoiceCommission;
use Pushery\Billing\Enums\ChargeType;
use Pushery\Billing\Events\RoutedSubscriptionInvoicePaid;
use Pushery\Billing\Exceptions\RoutedCycleUnreadable;
use Pushery\Billing\Marketplace\RoutedChargeLedger;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\ValueObjects\PlatformFee;
use Pushery\Billing\ValueObjects\RoutedInvoiceCommission;

/**
 * Writes the ledger row for a routed subscription cycle — the one sale the money ledger never saw.
 *
 * ## What was missing
 *
 * `RoutedChargeLedger::record()` had a single caller in the package: the one-time hosted lane. A routed
 * SUBSCRIPTION moved real money every cycle and left no row at all, so the sale was invisible to the three
 * things that read that table — the reversal caps, the earnings counter, and the small-business judgement.
 * Each of them answered as though the cycle had not happened, and each answer looked perfectly ordinary.
 *
 * ## Why a subscription cannot be recorded the way a one-time sale is
 *
 * The one-time lane writes its row when the checkout session opens, because at that moment there is exactly
 * one sale with one known amount. A subscription is priced with a RATE — the lane sets
 * `application_fee_percent` and the provider applies it per invoice — so there is no moment at which the
 * figures are known in advance. They exist once per cycle, at the provider.
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
 * ledger holds a plausible wrong number that flows into a clawback cap and a tax judgement with nothing
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
    ) {}

    public function __invoke(RoutedSubscriptionInvoicePaid $event): void
    {
        $subscription = Subscription::query()
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

        $this->ledger->record(
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
            $commission->feeBps === null ? null : new PlatformFee(bps: $commission->feeBps, flatMinor: 0),
            // The hosted subscription lane is unconditionally a destination charge -- `StripeCheckout`
            // refuses the other lane outright -- so the row states the lane it actually took rather than
            // reading today's configuration back when a refund needs to know.
            ChargeType::Destination,
            // Zero, stated rather than left null. The rate is applied to the invoice total with no tax rate
            // separating a net from a gross, and null on this column means "written before this was
            // recorded" -- a description of old rows, which this is not.
            0,
        );
    }
}
