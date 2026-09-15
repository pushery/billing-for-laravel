<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use Pushery\Billing\Contracts\MovesMerchantShare;
use Pushery\Billing\Contracts\NamesPaymentTransfer;
use Pushery\Billing\Contracts\ReportsMovedShares;
use Pushery\Billing\Contracts\ReversesMerchantShare;
use Pushery\Billing\ValueObjects\MerchantAccountReference;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\MovedShare;
use Pushery\Billing\ValueObjects\TransferResult;
use Pushery\Billing\ValueObjects\TransferReversal;
use RuntimeException;
use Stripe\Charge;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\RateLimitException;
use Stripe\StripeClient;
use Stripe\Transfer;

/**
 * The second half of a separate-transfer sale: moving the merchant's share to them.
 *
 * ## `source_transaction` is the whole point of this class
 *
 * A transfer created without it is funded by the platform's available balance. That reads as a detail and is
 * not one:
 *
 * - It **fails when the balance is short**, which for a platform that pays out promptly is the normal state
 *   rather than an exception — the buyer's money has not settled yet.
 * - It **succeeds by accident when the balance is not short**, paying the merchant out of somebody else's
 *   payment, and nothing anywhere records which.
 * - It **loses the link** between the money a buyer paid and the money a merchant received. Reconciliation
 *   needs that link, a reversal acts on it, and it cannot be reconstructed afterwards.
 *
 * Naming the charge makes the provider wait for that specific payment to settle and then move its share.
 * That is the behavior a separate transfer is supposed to have.
 *
 * ## And it has to name a CHARGE, which the ledger does not hold
 *
 * The reference a caller passes is the one the payment lane recorded: the PaymentIntent a payment created
 * (`pi_…`), or the invoice a routed subscription cycle is keyed on (`in_…`). Stripe's `source_transaction` takes
 * the id of a charge. This class used to send the reference as it stood, so every separate transfer named a
 * PaymentIntent where a charge belongs, while the tests fed it `ch_1` and stayed green. The reference is now
 * resolved here, at the one boundary that knows what a Stripe id is: an invoice to the payment behind it, a
 * payment to its `latest_charge`. A charge id (`ch_…`, `py_…`) is already what the field wants.
 *
 * ## The idempotency key is the caller's, and deliberately so
 *
 * This class does not invent one. A key derived here would have to come from the arguments, and the amount
 * is the one argument most likely to be recomputed slightly differently on a retry — which produces a second
 * key and a second transfer. The caller holds stable local state (a row id) and is the only party that can
 * key this safely.
 */
final readonly class StripeMerchantTransfers implements MovesMerchantShare, NamesPaymentTransfer, ReportsMovedShares, ReversesMerchantShare
{
    public function __construct(private StripeClient $stripe) {}

    public function transferShare(
        MerchantAccountReference $destination,
        Money $amount,
        string $sourceCharge,
        ?string $idempotencyKey = null,
    ): TransferResult {
        $transfer = $this->stripe->transfers->create(
            [
                'amount' => $amount->minorUnits,
                'currency' => strtolower($amount->currency),
                'destination' => $destination->accountId,
                // Funded by THIS payment, never by the platform balance. See the class docblock.
                'source_transaction' => $this->fundingChargeOf($sourceCharge),
            ],
            $idempotencyKey === null ? [] : ['idempotency_key' => $idempotencyKey],
        );

        // The provider's own figure, not the requested one. They can differ -- a currency conversion, a
        // capped amount -- and reporting what was asked for rather than what moved would put a number in the
        // ledger that no money matches.
        return new TransferResult(
            (string) $transfer->id,
            new Money((int) $transfer->amount, strtoupper((string) $transfer->currency)),
        );
    }

    /**
     * The id of the charge that funds a transfer, from the payment reference the ledger holds.
     *
     * A payment with no charge behind it yet refuses, and so does an invoice with no payment: there is nothing
     * that could fund the transfer, and a transfer sent without `source_transaction` would draw on the platform
     * balance instead, which is the failure the parameter exists to prevent. The caller records the refusal as
     * a share that did not move, where the retry command finds it.
     */
    private function fundingChargeOf(string $reference): string
    {
        if (str_starts_with($reference, 'in_')) {
            $reference = $this->intentOfInvoice($reference)
                ?? throw new RuntimeException("Stripe names no payment behind invoice {$reference}, so no charge can fund the transfer from it.");
        }

        if (! str_starts_with($reference, 'pi_')) {
            return $reference;
        }

        $charge = $this->stripe->paymentIntents->retrieve($reference)->latest_charge ?? null;
        $chargeId = $charge instanceof Charge ? $charge->id : $charge;

        if (! is_string($chargeId) || $chargeId === '') {
            throw new RuntimeException("Stripe names no charge behind payment {$reference} yet, so the transfer cannot be funded from it.");
        }

        return $chargeId;
    }

    /**
     * The transfer a destination charge's share went out on, read off the payment's charge.
     *
     * A PaymentIntent does not carry it; its charge does. So a payment is read with `latest_charge` expanded, an
     * invoice-keyed cycle through the payment behind it, and a charge id directly.
     */
    public function transferOfPayment(string $paymentReference): ?string
    {
        $reference = str_starts_with($paymentReference, 'in_') ? $this->intentOfInvoice($paymentReference) : $paymentReference;

        if ($reference === null) {
            return null;
        }

        $charge = str_starts_with($reference, 'pi_')
            ? ($this->stripe->paymentIntents->retrieve($reference, ['expand' => ['latest_charge']])->latest_charge ?? null)
            : $this->stripe->charges->retrieve($reference);

        $transfer = $charge instanceof Charge ? ($charge->transfer ?? null) : null;
        $transferId = $transfer instanceof Transfer ? $transfer->id : $transfer;

        return is_string($transferId) && $transferId !== '' ? $transferId : null;
    }

    /** The PaymentIntent behind an invoice, with its payments expanded, or null when it names none. */
    private function intentOfInvoice(string $invoice): ?string
    {
        return StripeInvoicePayments::intentIdOf(
            $this->stripe->invoices->retrieve($invoice, ['expand' => ['payments']])->toArray(),
        );
    }

    public function movedShare(string $transferReference): ?MovedShare
    {
        try {
            $transfer = $this->stripe->transfers->retrieve($transferReference);
        } catch (RateLimitException $e) {
            // A 429 is TRANSIENT and only lands here because the SDK makes RateLimitException a
            // subclass of InvalidRequestException. Swallowing it files "try again" as "never".
            throw $e;
        } catch (InvalidRequestException $missing) {
            // A reference the provider does not know. Returned as null rather than thrown, because the
            // caller is a reconciliation sweep: one unknown reference is the finding it exists to surface,
            // and an exception here would abandon every merchant after it.
            //
            // NARROWED TO 404, and the clause above is why that is not the same statement twice. The
            // dedicated rate-limit clause takes the 429 out of this branch entirely; the status check then
            // covers every OTHER non-404 the parent type carries. Both matter: without the first a busy
            // provider is reported as a transfer that does not exist — the most serious alarm this class
            // can raise, manufactured — and without the second any other refusal reads the same way.
            // Reporting over an unreadable provider is the one answer a reconciliation must never give.
            if ($missing->getHttpStatus() !== 404) {
                throw $missing;
            }

            return null;
        }

        $currency = strtoupper((string) $transfer->currency);

        // `amount_reversed` is the provider's CUMULATIVE figure for this transfer. Summing the reversal
        // objects instead would be the same number until a redelivered webhook or a partial second reversal
        // makes it not, and it would then read as more clawed back than actually was.
        return new MovedShare(
            (string) $transfer->id,
            new Money((int) $transfer->amount, $currency),
            new Money((int) ($transfer->amount_reversed ?? 0), $currency),
        );
    }

    public function reverseShare(
        string $transferReference,
        Money $amount,
        ?string $idempotencyKey = null,
    ): TransferReversal {
        // A reversal is created ON the transfer, not as a top-level object, which is why the reference the
        // caller holds is the transfer's and not a payment's. This is the call the separate-transfer lane
        // never had: refunding the payment does not touch a transfer that moved in its own request, so
        // without this a marketplace on the shipped default could pay a merchant and claw back nothing.
        $reversal = $this->stripe->transfers->createReversal(
            $transferReference,
            ['amount' => $amount->minorUnits],
            $idempotencyKey === null ? [] : ['idempotency_key' => $idempotencyKey],
        );

        // The provider's own figure, exactly as the outbound side does it -- and it matters more here. They
        // can differ when part of the transfer has already been reversed, and a ledger that recorded the
        // REQUESTED amount would believe the clawback was complete and never ask for the rest, leaving the
        // difference with the merchant while every total still adds up.
        return new TransferReversal(
            (string) $reversal->id,
            new Money((int) $reversal->amount, strtoupper((string) $reversal->currency)),
        );
    }
}
