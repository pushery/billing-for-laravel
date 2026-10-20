<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use InvalidArgumentException;

/**
 * The facts a seller outside the Union declares before the platform supplies its goods.
 *
 * Where the seller is established decides whether the platform is treated as the supplier at all (Art. 14a(2) VAT
 * Directive), its tax numbers are what it holds elsewhere, the goods are what the rate depends on, and where they are
 * dispatched from places the supply. These are the facts the Commission's explanatory notes on the e-commerce rules
 * name for a platform to ask a seller for (section 2.1.9), and what the platform may rely on under Art. 5c
 * Implementing Regulation 282/2011. The price, returns and cancellations belong to each sale and stand on its documents.
 */
final readonly class DeclaredSeller
{
    /**
     * @param  string  $establishedIn  the country of the seller's establishment, as an ISO 3166 code
     * @param  string  $goods  what the seller sells, in the seller's words
     * @param  ?string  $dispatchedFrom  the country the goods are dispatched from, as an ISO 3166 code, where the seller names one
     * @param  list<string>  $taxNumbers  the tax numbers the seller holds, VAT identification numbers included
     */
    public function __construct(
        public string $establishedIn,
        public string $goods,
        public ?string $dispatchedFrom = null,
        public array $taxNumbers = [],
    ) {
        foreach (['establishedIn' => $establishedIn, 'dispatchedFrom' => $dispatchedFrom] as $name => $country) {
            if ($country !== null && preg_match('/^[A-Z]{2}$/', $country) !== 1) {
                throw new InvalidArgumentException("A declared {$name} is a country as two capital letters of ISO 3166; got [{$country}].");
            }
        }

        if (trim($goods) === '') {
            throw new InvalidArgumentException('A seller declaration names the goods the seller sells.');
        }

        foreach ($taxNumbers as $number) {
            if (trim($number) === '') {
                throw new InvalidArgumentException('A declared tax number cannot be empty.');
            }
        }
    }
}
