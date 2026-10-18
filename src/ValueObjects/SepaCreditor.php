<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

/**
 * The payee a SEPA direct debit mandate names: who collects the debits, and under which creditor identifier.
 *
 * A provider that collects on a merchant's behalf is the payee itself, and the mandate says on whose behalf it
 * collects. The address is written without its country, which `country` carries as an ISO 3166 code, so a
 * mandate wording names the country in its own language.
 */
final readonly class SepaCreditor
{
    public function __construct(
        public string $name,
        public string $address,
        public string $country,
        public string $identifier,
    ) {}
}
