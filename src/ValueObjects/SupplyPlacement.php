<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Pushery\Billing\Enums\PlaceOfSupplyRule;
use Pushery\Billing\Enums\RecipientTaxStatus;

/**
 * Where a supply is taxed, who accounts for the tax, and which reporting scheme it belongs in.
 *
 * The three travel together because they are one decision with three consequences, and separating them is
 * how they drift: a supply can be correctly rated and still be reported into a scheme it does not belong
 * to. That combination has no visible symptom — the document charges a real rate to a real country — and
 * it corrupts a whole return rather than one line, because the population reported is wrong, not the amount.
 */
final readonly class SupplyPlacement
{
    public function __construct(
        /** Where it is taxed, AFTER the buyer's status has had its say. */
        public PlaceOfSupplyRule $rule,
        public RecipientTaxStatus $recipient,
        /** Whether the buyer accounts for the tax instead of the seller. */
        public bool $reverseCharge,
        /**
         * Whether it belongs in the consumer one-stop-shop return.
         *
         * A business supply never does, however cross-border it is: the scheme exists for consumers, and a
         * business line in it makes the return's population wrong rather than merely its total.
         */
        public bool $reportableUnderOneStopShop,
    ) {}
}
