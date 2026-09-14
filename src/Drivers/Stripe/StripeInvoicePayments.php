<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

/**
 * The payment intent behind a paid invoice, read one way for everything that needs it.
 *
 * The pinned API version links an invoice to its payment only through the `payments` collection, and only
 * when the invoice is fetched asking for it: a delivered `invoice.payment_succeeded` payload carries no
 * link at all. Two places need the answer. Reading the routed commission needs it for the money and the
 * recipient, and refunding a routed subscription cycle needs it because the ledger keys that cycle by its
 * invoice while the refund API only takes a payment intent. Two copies of the reading would drift the day
 * the collection changes shape, and one of them would start refunding against nothing.
 *
 * @internal
 */
final class StripeInvoicePayments
{
    /**
     * The first payment intent named in an invoice's expanded `payments`, or null when it names none.
     *
     * @param  array<array-key, mixed>  $invoice
     */
    public static function intentIdOf(array $invoice): ?string
    {
        $payments = $invoice['payments'] ?? null;

        /** @var array<array-key, mixed> $rows */
        $rows = is_array($payments) && is_array($payments['data'] ?? null) ? $payments['data'] : [];

        foreach ($rows as $row) {
            $payment = is_array($row) ? ($row['payment'] ?? null) : null;
            $intent = is_array($payment) ? ($payment['payment_intent'] ?? null) : null;

            if (is_string($intent) && $intent !== '') {
                return $intent;
            }
        }

        return null;
    }
}
