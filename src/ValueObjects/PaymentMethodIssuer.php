<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

/**
 * A bank or brand a customer picks within a payment method, such as a bank for KBC/CBC or a brand of gift card.
 */
final readonly class PaymentMethodIssuer
{
    /**
     * @param  string  $id  the provider's id of the issuer, passed back when the payment names it
     * @param  string  $name  the name to show
     * @param  ?string  $image  the address of the issuer's logo, the vector version where the provider has one
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $image = null,
    ) {}
}
