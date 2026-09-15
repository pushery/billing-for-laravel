<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

/**
 * The subscription behind an invoice, read one way for everything that needs it.
 *
 * Stripe moved the link with `2025-03-31.basil`: an invoice no longer carries `subscription`, it names its
 * subscription under `parent.subscription_details.subscription`. The package pins a later version, so every invoice
 * its own client fetches has that shape. A delivered webhook is rendered in the version of its ENDPOINT, though,
 * and an endpoint created before `basil` still sends the old field. Both are read, the pinned shape first.
 *
 * Three readers used to ask the old field on their own, and none of them could notice that it had gone. A cycle
 * whose invoice names no subscription looks exactly like a one-off invoice, and all three answer a one-off invoice
 * with silence: no cycle row, no taxed country, no terms.
 *
 * @internal
 */
final class StripeInvoiceSubscription
{
    /**
     * The id of the subscription that raised an invoice, or null for an invoice no subscription raised.
     *
     * @param  array<array-key, mixed>  $invoice
     */
    public static function idOf(array $invoice): ?string
    {
        $parent = $invoice['parent'] ?? null;
        $details = is_array($parent) ? ($parent['subscription_details'] ?? null) : null;
        $pinned = is_array($details) ? ($details['subscription'] ?? null) : null;

        if (is_string($pinned) && $pinned !== '') {
            return $pinned;
        }

        $legacy = $invoice['subscription'] ?? null;

        return is_string($legacy) && $legacy !== '' ? $legacy : null;
    }
}
