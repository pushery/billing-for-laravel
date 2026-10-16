<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use InvalidArgumentException;

/**
 * A billing or shipping address for a payment, as a provider that asks for one needs it.
 *
 * Some methods refuse a payment without the billing address, Klarna and in3 among them. A provider expects an email
 * address, or a postal address made of the street and number, the postal code, the city and the country.
 */
final readonly class PaymentAddress
{
    /**
     * @param  ?string  $country  ISO 3166-1 alpha-2, such as `DE`
     *
     * @throws InvalidArgumentException for a country that is not two capital letters
     */
    public function __construct(
        public ?string $givenName = null,
        public ?string $familyName = null,
        public ?string $organizationName = null,
        public ?string $streetAndNumber = null,
        public ?string $streetAdditional = null,
        public ?string $postalCode = null,
        public ?string $city = null,
        public ?string $region = null,
        public ?string $country = null,
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $title = null,
    ) {
        if ($country !== null && preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            throw new InvalidArgumentException("The country of a payment address is its ISO 3166-1 code in two capital letters, such as `DE`, and `{$country}` is not.");
        }
    }
}
