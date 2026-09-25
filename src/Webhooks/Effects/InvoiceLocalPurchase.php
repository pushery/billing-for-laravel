<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks\Effects;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Pushery\Billing\Enums\OrderStatus;
use Pushery\Billing\Events\AddonPurchased;
use Pushery\Billing\Invoicing\OrderInvoiceIssuer;
use Pushery\Billing\Models\Order;

/**
 * Closes the local order of a one-time purchase once the provider confirms the payment, and raises its invoice.
 *
 * A provider that issues its own documents needs none of this. A local engine wrote an order when the checkout
 * opened (on Mollie, `MollieOneTimeCharge`), and the invoice is raised from that order or not at all, the same way
 * a cycle's invoice is raised from the cycle's order.
 *
 * ## It selects itself, rather than being wired per driver
 *
 * It acts only where a local order was written under the payment reference, with no subscription behind it and
 * still in flight. A Stripe purchase has no such order and passes through untouched, and a cycle's order is settled
 * by the engine that billed it. No driver name appears in the decision, as in {@see IssueLocalCreditNote}.
 *
 * ## A redelivery changes nothing
 *
 * The order is closed on the first delivery, so the next one finds nothing in flight. The issuer holds one invoice
 * per order in the database as well, so a second attempt could not mint a second number either.
 *
 * ## Paid first, the document after
 *
 * The money has moved when this runs. The issuer swallows its own failure for the reason it gives: a missing invoice
 * is recoverable, an order left in flight after the money arrived is not.
 */
final readonly class InvoiceLocalPurchase
{
    public function __construct(private OrderInvoiceIssuer $invoices) {}

    public function __invoke(AddonPurchased $event): void
    {
        $reference = $event->paymentReference;

        if ($reference === null || $reference === '') {
            return;
        }

        $order = Order::model()::query()
            ->where('payment_reference', $reference)
            ->whereNull('subscription_id')
            ->where('status', OrderStatus::Processing)
            ->when($event->provider !== null, static fn (Builder $query): Builder => $query->where('provider', $event->provider))
            ->first();

        if (! $order instanceof Order) {
            return;
        }

        $order->update(['status' => OrderStatus::Paid, 'processed_at' => Carbon::now()]);

        $this->invoices->issue($order->fresh() ?? $order);
    }
}
