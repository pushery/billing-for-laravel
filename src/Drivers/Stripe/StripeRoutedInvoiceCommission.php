<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use Pushery\Billing\Contracts\ReadsRoutedInvoiceCommission;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\RoutedInvoiceCommission;
use Stripe\Exception\RateLimitException;
use Stripe\StripeClient;
use Throwable;

/**
 * Reads a routed cycle's commission back out of Stripe.
 *
 * ## The route, measured rather than assumed
 *
 * Measured 2026-08-07 against the pinned API `2025-08-27.basil`, on a real paid invoice, and the result
 * contradicted what the SDK reads like:
 *
 * ```
 * via retrieve+expand: pi_3U1bayFVuDh8cjjt0VJBQdE4
 * in webhook payload:  (none)
 * payload has `payments` key: no
 * ```
 *
 * **The delivered `invoice.payment_succeeded` payload carries no link to the payment at all** — no `charge`,
 * no `latest_charge`, no `payment_intent`, and not even the `payments` collection the link lives in. A
 * webhook payload is never expanded, so the invoice has to be fetched again asking for it.
 *
 * The SDK cannot answer this, and reading it as though it could is the trap: its `@param` blocks describe
 * what may be SENT when creating an invoice, not what comes back. An earlier draft of this lane was written
 * against a payload Stripe never sends, on exactly that misreading.
 *
 * So: **invoice (expanded) → payment intent → subscription**. Three calls, and each buys something the one
 * before it cannot:
 *
 * 1. the invoice, expanded, is the only way to the payment intent's id;
 * 2. the payment intent carries `application_fee_amount` and `transfer_data.destination` — the money and
 *    the recipient, which the invoice object itself does not have;
 * 3. the subscription carries `application_fee_percent` — the TERMS, which a partial clawback needs and
 *    which cannot be recovered from the amounts without inventing a rounding rule.
 *
 * Three calls on a queued webhook effect cost queue time, never response time: the effects run through
 * `ShouldQueueAfterCommit`, so none of this is paid against the provider's webhook timeout.
 *
 * ## A permanent failure answers null; a retryable one is rethrown
 *
 * A missing intent, a refused call, an invoice that was never routed: all answer null, and the caller turns
 * that into a refusal to mark the event done. That is the whole reason this lane READS instead of computing
 * — a failed read is an event somebody sees, while a computed commission that drifts is a plausible number
 * in a money ledger that nobody ever questions.
 *
 * A rate limit is the exception, and it has to be. Stripe's SDK makes `RateLimitException` a subclass of
 * `InvalidRequestException`, so a broad clause files a throttled call as a permanent rejection: the caller
 * raises its unreadable-cycle refusal, and an operator goes looking for a deleted invoice that is sitting
 * exactly where it always was. Rethrown here, the queue simply asks again.
 */
final readonly class StripeRoutedInvoiceCommission implements ReadsRoutedInvoiceCommission
{
    public function __construct(private StripeClient $stripe) {}

    public function forInvoice(string $invoiceReference): ?RoutedInvoiceCommission
    {
        try {
            // Expanded on purpose. Without `payments` the collection is absent rather than empty, and the
            // code would fall through to "no payment found" for every single invoice -- silently, and
            // looking exactly like an unrouted one.
            $invoice = $this->stripe->invoices->retrieve($invoiceReference, ['expand' => ['payments']]);

            $intentId = $this->paymentIntentIdOf($invoice->toArray());

            if ($intentId === null) {
                return null;
            }

            $intent = $this->stripe->paymentIntents->retrieve($intentId)->toArray();

            $destination = $intent['transfer_data'] ?? null;
            $account = is_array($destination) ? ($destination['destination'] ?? null) : null;
            $fee = $intent['application_fee_amount'] ?? null;
            $currency = $intent['currency'] ?? null;

            // Not routed. A plain platform subscription has neither, and that is the ordinary case rather
            // than a failure -- the caller writes no ledger row for it, exactly as before this lane existed.
            if (! is_string($account) || $account === '' || ! is_int($fee) || ! is_string($currency)) {
                return null;
            }

            $gross = $invoice->toArray()['amount_paid'] ?? null;

            if (! is_int($gross)) {
                return null;
            }

            return new RoutedInvoiceCommission(
                merchantAccountReference: $account,
                gross: Money::of($gross, strtoupper($currency)),
                fee: Money::of($fee, strtoupper($currency)),
                feeBps: $this->feeBpsOf($invoice->toArray()),
            );
        } catch (RateLimitException $exception) {
            // A 429 is "ask again", never "not routed".
            throw $exception;
        } catch (Throwable) {
            // Everything else answers null, which the caller turns back into a refusal to complete. Letting
            // it propagate would retry the whole effect on a permanent condition (a deleted invoice, a
            // revoked key) forever; answering null lets the caller say what it could not do, once.
            return null;
        }
    }

    /**
     * The payment intent behind a paid invoice, reachable only through the expanded `payments` collection.
     *
     * @param  array<array-key, mixed>  $invoice
     */
    private function paymentIntentIdOf(array $invoice): ?string
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

    /**
     * The rate this cycle was billed at, from the subscription that raised the invoice.
     *
     * Read rather than derived from the two amounts. `fee ÷ gross` looks exact and is not: the provider
     * rounds, so the recovered figure is right for most invoices and one basis point out for some — and a
     * clawback computed from a rate that is one out is wrong by real money in a way no test would think to
     * look for.
     *
     * Null when the invoice names no subscription, or the call fails. The row then records no terms, which
     * is honest: a partial clawback on it has to refuse rather than guess.
     *
     * @param  array<array-key, mixed>  $invoice
     */
    private function feeBpsOf(array $invoice): ?int
    {
        $subscription = $invoice['subscription'] ?? null;

        if (! is_string($subscription) || $subscription === '') {
            return null;
        }

        try {
            $percent = $this->stripe->subscriptions->retrieve($subscription)->toArray()['application_fee_percent'] ?? null;
        } catch (RateLimitException $exception) {
            // Rethrown for a sharper reason than the call above. A throttled TERMS lookup that answered null
            // would write a row with no rate on it -- and null there means "unknown", so a later partial
            // clawback refuses. A retryable failure would have quietly cost the row its terms forever.
            throw $exception;
        } catch (Throwable) {
            return null;
        }

        // The lane writes `application_fee_percent = bps / 100`, so this reverses exactly what was sent.
        // Read as a number rather than a float alone: Stripe answers this field as a decimal string on some
        // versions, and a strict float check would drop the terms on those without saying why.
        return is_int($percent) || is_float($percent) || (is_string($percent) && is_numeric($percent))
            ? (int) round(((float) $percent) * 100)
            : null;
    }
}
