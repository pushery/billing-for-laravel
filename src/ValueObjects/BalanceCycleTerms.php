<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Pushery\Billing\Enums\TaxArchetype;

/**
 * What one period of a subscription paid from an internal balance is sold under: the commission, the buyer's tax and
 * what the subscription sells.
 *
 * The facts `RoutedPayment::chargeFromBalance()` takes from the caller of a one-off sale, gathered in one value so a
 * renewal that no caller starts can be handed them by the host's `BalanceSubscriptionTerms`.
 */
final readonly class BalanceCycleTerms
{
    public function __construct(
        /** The platform's cut, taken on the period's net. */
        public PlatformFee $fee,
        /** The tax on the buyer's side, in basis points. */
        public int $taxBps,
        /** Whether the small-value rules of the seller's own country apply, which decides the tier of the document. */
        public bool $buyerIsDomestic,
        /** What the subscription sells. */
        public TaxArchetype $archetype,
        /** The buyer's country, where the tax was decided from it. */
        public ?string $destinationCountry = null,
        /** Where the caller that decided the tax placed the sale. */
        public ?SupplyPlacement $placement = null,
    ) {}
}
