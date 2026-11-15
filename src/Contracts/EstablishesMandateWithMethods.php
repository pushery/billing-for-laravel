<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\ValueObjects\MandateHandshake;
use Pushery\Billing\ValueObjects\Money;

/**
 * A driver whose redirect mandate can be offered through payment methods the application names.
 *
 * A sibling of {@see EstablishesMandateByRedirect} rather than a parameter on its method, for the reason that
 * one is a sibling of `PaymentRails`: the interface has implementations outside this package, and a parameter
 * added to its method is a fatal error in code the package does not own.
 */
interface EstablishesMandateWithMethods
{
    /**
     * Begin establishing a mandate through one of the given payment methods, returning where to send the customer.
     *
     * @param  non-empty-list<string>  $methods  The provider's names of the payment methods the checkout offers.
     * @param  Money  $verification  The first payment's amount. It may be zero where the provider takes a first
     *                               payment without a charge for every method named, so the customer sees
     *                               nothing on a statement; otherwise it is a real charge, as for
     *                               {@see EstablishesMandateByRedirect::beginMandate()}.
     */
    public function beginMandateWith(string $customerReference, Money $verification, string $returnUrl, array $methods): MandateHandshake;
}
