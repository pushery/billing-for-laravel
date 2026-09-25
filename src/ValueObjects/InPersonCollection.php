<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Pushery\Billing\Tax\SaleTaxFacts;

/**
 * A sale that is up on a reader, waiting for the buyer's card.
 *
 * The tax is decided at this point and does not change afterwards: the place is the reader's location, and the
 * split is of the gross the reader shows. Whether the buyer paid is the provider's answer, which arrives later.
 */
final readonly class InPersonCollection
{
    public function __construct(
        /** The provider's reference for the payment, which its confirmation names. */
        public string $paymentReference,
        public string $reader,
        /** The country the sale was placed in: the country of the reader's location. */
        public string $soldAt,
        public Money $gross,
        public SaleTaxFacts $tax,
    ) {}
}
