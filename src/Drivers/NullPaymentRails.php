<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers;

use Pushery\Billing\Contracts\PaymentRails;
use Pushery\Billing\Exceptions\BillingDisabled;
use Pushery\Billing\ValueObjects\ChargeResult;
use Pushery\Billing\ValueObjects\MandateReference;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\RefundResult;
use Pushery\Billing\ValueObjects\TokenizedMethod;

/**
 * The rails of the NullDriver: every money operation fails loudly with BillingDisabled, because
 * charging while the master switch is off is a programming error (routes are 404'd), not a state to
 * recover from silently.
 */
final class NullPaymentRails implements PaymentRails
{
    public function charge(Money $amount, string $token, ?string $idempotencyKey = null): ChargeResult
    {
        throw BillingDisabled::cannot('charge');
    }

    public function createMandate(string $customerReference, string $token): MandateReference
    {
        throw BillingDisabled::cannot('create a mandate');
    }

    public function tokenize(string $paymentData): TokenizedMethod
    {
        throw BillingDisabled::cannot('tokenize a payment method');
    }

    public function offSessionCharge(Money $amount, MandateReference $mandate, ?string $idempotencyKey = null): ChargeResult
    {
        throw BillingDisabled::cannot('charge off-session');
    }

    public function refund(string $chargeReference, Money $amount, ?string $idempotencyKey = null): RefundResult
    {
        throw BillingDisabled::cannot('refund');
    }
}
