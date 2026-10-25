<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Shopify;

use Pushery\Billing\Contracts\PaymentRails;
use Pushery\Billing\Exceptions\NotPossibleOnShopify;
use Pushery\Billing\ValueObjects\ChargeNarrative;
use Pushery\Billing\ValueObjects\ChargeOptions;
use Pushery\Billing\ValueObjects\ChargeResult;
use Pushery\Billing\ValueObjects\ChargeRouting;
use Pushery\Billing\ValueObjects\MandateReference;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\RefundResult;
use Pushery\Billing\ValueObjects\TokenizedMethod;

/**
 * The rails of the Shopify driver, which carry nothing.
 *
 * Shopify bills the merchant on the Shopify invoice and pays the app's share out to its partner account. The
 * app never holds a payment method of the merchant's, so there is no token to charge, no mandate to keep and no
 * charge of the app's own to refund. Each method refuses and names what does charge a merchant on Shopify: a
 * subscription, a usage record or a one-time purchase.
 */
final readonly class ShopifyPaymentRails implements PaymentRails
{
    public function charge(Money $amount, string $token, ?string $idempotencyKey = null, ?ChargeRouting $routing = null, ?ChargeNarrative $narrative = null, ?ChargeOptions $options = null): ChargeResult
    {
        throw NotPossibleOnShopify::useThePaymentRails('charge a payment method');
    }

    public function createMandate(string $customerReference, string $token): MandateReference
    {
        throw NotPossibleOnShopify::useThePaymentRails('store a mandate');
    }

    public function tokenize(string $paymentData): TokenizedMethod
    {
        throw NotPossibleOnShopify::useThePaymentRails('describe a payment method');
    }

    public function offSessionCharge(Money $amount, MandateReference $mandate, ?string $idempotencyKey = null, ?ChargeRouting $routing = null, ?ChargeNarrative $narrative = null): ChargeResult
    {
        throw NotPossibleOnShopify::useThePaymentRails('charge a mandate');
    }

    public function refund(string $chargeReference, Money $amount, ?string $idempotencyKey = null, ?ChargeRouting $routing = null): RefundResult
    {
        throw NotPossibleOnShopify::useThePaymentRails('refund a charge');
    }
}
