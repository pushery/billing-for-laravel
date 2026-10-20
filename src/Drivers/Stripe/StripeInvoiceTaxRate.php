<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use Stripe\StripeClient;

/**
 * The one tax rate a Stripe invoice charged, in basis points, read one way for everything that takes a commission on
 * an invoice's net.
 *
 * Read from the tax rate the invoice names, never derived from its two amounts. The amounts are rounded per line, so
 * their quotient misses the rate on a small invoice, and a rate on a merchant charge is the base of every partial
 * clawback and of the small-business turnover after it. A rate one basis point out is wrong by real money there,
 * with nothing to notice.
 *
 * Zero, which leaves the payment as the base, for an invoice that charged no tax, and for one this cannot state as
 * one rate: two rates on one invoice, a rate the provider names without an id, or one finer than a whole basis point.
 * That is the base a cycle had before the rate was read, and a caller states it as the zero it is.
 *
 * An entry that charged nothing is skipped, as the session reader skips it: an exempt jurisdiction beside a taxed one
 * is not a second rate. The pinned version names the rate under `total_taxes`, an invoice in an older version under
 * `total_tax_amounts`, and both are read.
 *
 * A failed read of the rate is not one of those zeros. It propagates, and each caller says what a failure leaves: the
 * reader of a paid cycle refuses rather than price the cycle on the gross, and the setter of a draft leaves the
 * percentage the provider applies anyway.
 *
 * @internal
 */
final class StripeInvoiceTaxRate
{
    /**
     * @param  array<array-key, mixed>  $invoice
     */
    public static function pointsOf(StripeClient $stripe, array $invoice): int
    {
        $rates = [];

        foreach ([$invoice['total_taxes'] ?? null, $invoice['total_tax_amounts'] ?? null] as $entries) {
            foreach (is_array($entries) ? $entries : [] as $entry) {
                if (! is_array($entry) || ! is_int($entry['amount'] ?? null) || $entry['amount'] === 0) {
                    continue;
                }

                $details = $entry['tax_rate_details'] ?? null;
                $rate = is_array($details) ? ($details['tax_rate'] ?? null) : ($entry['tax_rate'] ?? null);

                if (! is_string($rate) || $rate === '') {
                    return 0;
                }

                $rates[$rate] = true;
            }
        }

        if (count($rates) !== 1) {
            return 0;
        }

        $object = $stripe->taxRates->retrieve(array_key_first($rates))->toArray();

        // The rate actually applied where Stripe Tax computed it, the stated one for a rate the account defined.
        return StripeTaxRatePoints::of($object['effective_percentage'] ?? $object['percentage'] ?? null) ?? 0;
    }
}
