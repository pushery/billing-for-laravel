<?php

declare(strict_types=1);

namespace Pushery\Billing\Invoicing;

use Pushery\Billing\Enums\RecipientTaxStatus;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\ServicePeriod;
use Pushery\Billing\ValueObjects\SupplyTaxCharacteristics;

/**
 * The tax of one locally billed cycle, together with the basis it was determined on.
 *
 * ## Why the basis travels with the amount
 *
 * A tax figure on a numbered document is defensible only if the document also says how it was reached.
 * That is not a reporting nicety here: {@see Guards\TaxWithoutBasisGuard} refuses a `tax_minor` under a
 * driver that determines no tax unless the row records an archetype, a place of supply, a rate band or an
 * exemption reason. Handing the amount back on its own would produce exactly the document that guard
 * exists to stop, and the issuer would have to re-derive the basis to satisfy it — a second answer to a
 * question that must have one.
 *
 * ## Why the net is carried rather than computed from the rate
 *
 * The cycle was priced GROSS: the order total is what the customer was charged, and the tax is the part of
 * it that belongs to the state. Recomputing the net from the rate loses a minor unit the customer
 * nonetheless paid, and the two figures then no longer add up to the total the document states.
 * `Money::baseFromMarkup()` takes the tax as the remainder, so `net + tax == total` holds exactly.
 */
final readonly class DeterminedOrderTax
{
    public function __construct(
        /** What the supply was worth before tax — the total minus the tax, never recomputed from the rate. */
        public Money $net,
        /** What belongs to the state, which is zero on an exempt or reverse-charged supply. */
        public Money $tax,
        public int $rateBps,
        /** Whether the customer accounts for the tax instead of the seller. */
        public bool $reverseCharge,
        /** Whether no tax was charged for a stated legal reason. */
        public bool $exempt,
        /** Whether this cycle belongs in the cross-border consumer scheme's return. */
        public bool $oneStopShop,
        /** The archetype, place, band, exemption and destination the document freezes. */
        public SupplyTaxCharacteristics $characteristics,
        /** The period supplied, as the document states it — both dates inclusive. */
        public ServicePeriod $period,
        /** Who the buyer was for the placement: a consumer, a business in the union, or one outside it. */
        public RecipientTaxStatus $recipient,
        /**
         * The buyer's VAT ID, where a register confirmed it and the package could read its country.
         *
         * Only then, because the document states it as the ID the supply was decided on. An ID that was
         * merely present, or one whose country nobody could read, decided nothing and is not repeated here.
         */
        public ?string $buyerVatId,
        /** The country that ID registers the buyer in, which is where a business supply is placed. */
        public ?string $buyerCountry,
    ) {}
}
