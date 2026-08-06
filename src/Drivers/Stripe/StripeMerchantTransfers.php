<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use Pushery\Billing\Contracts\MovesMerchantShare;
use Pushery\Billing\Contracts\ReversesMerchantShare;
use Pushery\Billing\ValueObjects\MerchantAccountReference;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\TransferResult;
use Pushery\Billing\ValueObjects\TransferReversal;
use Stripe\StripeClient;

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
 * ## The idempotency key is the caller's, and deliberately so
 *
 * This class does not invent one. A key derived here would have to come from the arguments, and the amount
 * is the one argument most likely to be recomputed slightly differently on a retry — which produces a second
 * key and a second transfer. The caller holds stable local state (a row id) and is the only party that can
 * key this safely.
 */
final readonly class StripeMerchantTransfers implements MovesMerchantShare, ReversesMerchantShare
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
                'source_transaction' => $sourceCharge,
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
