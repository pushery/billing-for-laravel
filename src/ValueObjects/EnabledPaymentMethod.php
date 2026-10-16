<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

/**
 * One payment method a payment account has turned on, as its provider describes it.
 *
 * The id is the provider's own, `creditcard` at Mollie and `card` at Stripe, because a shop that runs two providers
 * activates and orders the methods of each.
 */
final readonly class EnabledPaymentMethod
{
    /**
     * @param  string  $id  the provider's id of the method, such as `ideal`
     * @param  string  $name  the name to show, in the requested language where the provider translates it
     * @param  ?string  $image  the address of the method's logo, the vector version where the provider has one
     * @param  ?Money  $minimumAmount  the smallest amount the method takes, where the provider states one
     * @param  ?Money  $maximumAmount  the largest amount the method takes, null without a limit or where none is stated
     * @param  list<PaymentMethodIssuer>  $issuers  the banks or brands a customer picks from, for the methods that have them
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $image = null,
        public ?Money $minimumAmount = null,
        public ?Money $maximumAmount = null,
        public array $issuers = [],
    ) {}
}
