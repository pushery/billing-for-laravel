<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\ValueObjects\ChargeResult;
use Pushery\Billing\ValueObjects\MandateReference;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\RefundResult;
use Pushery\Billing\ValueObjects\TokenizedMethod;

/**
 * The lower of the two billing layers: the smallest common denominator every payment driver
 * implements. It moves money and stores mandates; it knows nothing about subscriptions, invoices or
 * proration — those live in the BillingEngine above it. Everything crosses the boundary as Money and
 * value objects, never a raw provider response.
 *
 * THIS LAYER IS DELIBERATELY NOT ELIGIBILITY-GATED, and it must stay that way. The eligibility gate
 * (CanTransactMoney) belongs at the ENTRY seams — Checkout, OneTimeCharge, SubscriptionActions::swap —
 * where a payment BEGINS, not on every charge. Wrapping this interface in a gate looks like the tidier,
 * more central place for it and is the wrong answer twice over: it guards nothing today, and once dunning
 * retries exist it would start REFUSING legitimate collections. offSessionCharge() is what a retry uses,
 * and a subscriber who was eligible when they subscribed can later fail an age or KYC predicate — gating
 * here would block collecting money they already owe. That an entry-seam gate cannot be forgotten is
 * enforced structurally instead (tests/Unit/MoneyEntryGateTest.php).
 */
interface PaymentRails
{
    /**
     * Charge a payment token on-session (customer present). The optional idempotency key makes the
     * charge safe to retry: pass a stable key derived from the business operation (an order/invoice
     * id) and the provider collapses a duplicate submission onto the first result instead of charging
     * twice.
     */
    public function charge(Money $amount, string $token, ?string $idempotencyKey = null): ChargeResult;

    /** Store a reusable mandate/token for a customer so it can be charged later. */
    public function createMandate(string $customerReference, string $token): MandateReference;

    /** Tokenise raw payment data captured by the front-end element. */
    public function tokenize(string $paymentData): TokenizedMethod;

    /**
     * Charge a stored mandate off-session (merchant-initiated / MIT). The idempotency key is what makes
     * a queued/redelivered merchant-initiated charge retry-safe — pass the invoice/charge id so a
     * re-run collapses onto the first charge rather than billing the customer again.
     */
    public function offSessionCharge(Money $amount, MandateReference $mandate, ?string $idempotencyKey = null): ChargeResult;

    /** Refund a previous charge, in full or in part. The idempotency key prevents a double refund on retry. */
    public function refund(string $chargeReference, Money $amount, ?string $idempotencyKey = null): RefundResult;
}
