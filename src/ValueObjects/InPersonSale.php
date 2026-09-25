<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use InvalidArgumentException;
use Pushery\Billing\Enums\TaxArchetype;

/**
 * One sale at the counter: what was sold, and the gross the buyer pays for it on a card.
 *
 * The gross is the price the buyer was shown, tax included, which is how a counter sale is priced. The tax is split
 * out of it once the reader's location has said where the sale is made.
 *
 * A counter buyer usually stays anonymous, so the buyer is optional. It matters for a service, whose place still
 * follows the buyer when it is paid in person; goods handed over at the counter are placed there whoever buys them.
 */
final readonly class InPersonSale
{
    public function __construct(
        public TaxArchetype $sold,
        public Money $gross,
        /**
         * What was sold, in the words the receipt prints. A receipt at the counter has to name the goods or the
         * service it is for, so the sale cannot be put up without it.
         */
        public string $description,
        /** What a tip was paid on. A tip has no place of its own and takes the place of that supply. */
        public ?TaxArchetype $soldAlongside = null,
        /** The buyer, where the host knows them. Null for an anonymous buyer, who is placed at the counter. */
        public ?TaxContext $buyer = null,
        /**
         * The host's own reference for the sale, such as a ticket number. The provider collapses a repeated
         * request with the same reference onto the first, so a retried call does not put the sale up twice.
         */
        public ?string $reference = null,
    ) {
        if (! $gross->isPositive()) {
            throw new InvalidArgumentException("A sale at the counter needs a positive gross, and {$gross->format()} is not one.");
        }

        if (trim($description) === '') {
            throw new InvalidArgumentException('A sale at the counter needs a description, because its receipt has to name what was sold.');
        }

        if ($reference === '') {
            throw new InvalidArgumentException('A sale reference cannot be empty. Pass null for a sale the host keeps no reference for.');
        }
    }
}
