<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use Pushery\Billing\Contracts\TakesCycleCommissionOnNet;
use Pushery\Billing\ValueObjects\PlatformFee;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\RateLimitException;
use Stripe\StripeClient;

/**
 * Sets a drafted destination cycle's application fee on the net of its invoice, at Stripe.
 *
 * ## The draft is the window, and it is the only one
 *
 * A destination subscription carries `application_fee_percent`, which Stripe applies to the invoice total, tax
 * included. Stripe creates a renewal's invoice as a draft and finalizes it about an hour later, and until then the
 * invoice takes `application_fee_amount`, an absolute fee. With automatic tax the draft already carries its tax. So the
 * commission is taken where it is defined, on the net, before the payment withholds anything, and the alternative, a
 * partial refund of the fee after the payment, never arises: it would be a second movement to the merchant that the
 * transfer a reconciliation compares against does not show, and a later buyer refund would hand back a share of the
 * original fee rather than of the commission.
 *
 * The fee is the subscription's rate on the net of what the invoice will charge, `amount_due`, as a separate-transfer
 * cycle takes it on the net of what the buyer paid: a cycle that a credit balance pays in part takes the commission on
 * the part the payment carries.
 *
 * ## The setting is checked where it lands, not trusted
 *
 * The fee and the rate it was set on go into the invoice's metadata beside the fee itself. The reader of the paid cycle
 * takes the rate only where the fee Stripe withheld is the fee that was set: a draft that finalized before this call
 * arrived, or a fee Stripe recomputed from the percentage after all, then reads as the provider's percentage of the
 * total, which it is, and its row says so with a zero until the correction after the payment brings it to the net.
 *
 * ## What leaves the invoice as it is
 *
 * An invoice that is no longer a draft, one with no subscription or nothing to charge, one that charged no tax or names
 * no single rate, and a subscription not priced by a percentage, which is a plain platform subscription or one on the
 * separate-transfer lane, whose cycle takes its commission on the net when the share moves. A refused call means the
 * invoice is gone or finalized in the meantime, and it keeps the percentage too. A rate limit is rethrown, as is a
 * failure the provider did not answer, so the queue asks again while the draft may still be open.
 */
final readonly class StripeCycleCommissionOnNet implements TakesCycleCommissionOnNet
{
    /** The invoice metadata key that carries the tax rate the fee was set on, in basis points. */
    public const string TAX_BPS_KEY = 'billing_commission_tax_bps';

    /** The invoice metadata key that carries the fee that was set, in minor units. */
    public const string FEE_KEY = 'billing_commission_fee_minor';

    public function __construct(private StripeClient $stripe) {}

    public function onDraft(string $invoiceReference): void
    {
        try {
            $this->setOnNet($invoiceReference);
        } catch (RateLimitException $exception) {
            // Stripe's SDK makes a rate limit a kind of invalid request, so it is caught first: a 429 is "ask again".
            throw $exception;
        } catch (InvalidRequestException) {
            // Gone, or finalized before the update arrived. The cycle keeps the percentage of the total, and its row
            // will say so.
        }
    }

    /**
     * The tax rate a paid invoice's fee was set on, or zero where the fee it withheld is not the fee that was set.
     *
     * @param  array<array-key, mixed>  $invoice
     */
    public static function taxBpsSetOn(array $invoice, int $withheld): int
    {
        $metadata = $invoice['metadata'] ?? null;

        if (! is_array($metadata)) {
            return 0;
        }

        $fee = $metadata[self::FEE_KEY] ?? null;
        $bps = $metadata[self::TAX_BPS_KEY] ?? null;

        if (! is_string($fee) || ! is_string($bps) || $fee !== (string) $withheld || ! ctype_digit($bps)) {
            return 0;
        }

        return (int) $bps;
    }

    private function setOnNet(string $invoiceReference): void
    {
        $invoice = $this->stripe->invoices->retrieve($invoiceReference)->toArray();

        $subscription = StripeInvoiceSubscription::idOf($invoice);
        $due = $invoice['amount_due'] ?? null;
        $currency = $invoice['currency'] ?? null;

        if (($invoice['status'] ?? null) !== 'draft' || $subscription === null || ! is_int($due) || $due <= 0 || ! is_string($currency)) {
            return;
        }

        $percent = $this->stripe->subscriptions->retrieve($subscription)->toArray()['application_fee_percent'] ?? null;

        // The lane writes `application_fee_percent = bps / 100`, so this reverses what was sent, as the reader of the
        // paid cycle reads it, including the decimal string some versions answer with.
        if (! is_int($percent) && ! is_float($percent) && (! is_string($percent) || ! is_numeric($percent))) {
            return;
        }

        $taxBps = StripeInvoiceTaxRate::pointsOf($this->stripe, $invoice);

        if ($taxBps === 0) {
            return;
        }

        $terms = new PlatformFee(bps: (int) round(((float) $percent) * 100));
        $fee = StripeAmount::of($terms->of(StripeAmount::toMoney($due, strtoupper($currency))->baseFromMarkup($taxBps)[0]));

        $this->stripe->invoices->update($invoiceReference, [
            'application_fee_amount' => $fee,
            'metadata' => [self::TAX_BPS_KEY => (string) $taxBps, self::FEE_KEY => (string) $fee],
        ]);
    }
}
