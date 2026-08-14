<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Carbon\CarbonImmutable;
use Pushery\Billing\Enums\PlaceOfSupplyRule;
use Pushery\Billing\Enums\TaxArchetype;
use Pushery\Billing\Enums\TaxExemptionReason;
use Pushery\Billing\Enums\TaxRateCategory;

/**
 * What a supply WAS, in the terms its tax treatment turns on — carried as one argument.
 *
 * Every field is nullable and every field defaults to null, and that is the whole design rather than a
 * convenience. These characteristics are independently known: a caller may have the archetype from a
 * product catalog, the band from a rate matrix, and the destination from a place-of-supply evidence
 * record, with no single moment where all of them are in hand. An object that demanded them together
 * would push callers into inventing values, which is the failure this replaces — an invented tax
 * characteristic is worse than an absent one, because a reader cannot tell it from a measured one.
 *
 * ## Why an object now, when three separate parameters were once deliberate
 *
 * The docblock this replaces argued for separate parameters on the ground that they are independently
 * known and that "a wrapper would have to invent a rule for a partially-filled one". The first half is
 * still true and is why the fields are nullable. The second half was the part that did not survive:
 * there IS no rule to invent, because a partially-filled object is not a lesser object here. An empty
 * one is exactly as valid as a full one, and it means the same thing the absent arguments meant — the
 * caller does not know, and the document says so by leaving the columns null.
 *
 * What changed underneath the argument is the count. It was written when there were three; there are
 * eight, spread across a signature of seventeen parameters that two callers filled positionally. At that
 * width the ordering itself becomes a hazard: a characteristic inserted in the middle shifts every
 * positional argument after it, and two of the pairs it could shift past are type-compatible, so the
 * statics stay silent while a document acquires a wrong date. One argument cannot be mis-ordered.
 *
 * ## What does NOT belong here
 *
 * The payment provider and the charge reference. They travel together with the sale's tax
 * characteristics in several signatures and are not any of them: they identify the money, not the
 * supply, and a document can carry one without the other.
 */
final readonly class SupplyTaxCharacteristics
{
    /**
     * @param  ?TaxArchetype  $archetype  what was sold, in the terms the tax treatment turns on
     * @param  ?TaxArchetype  $soldAlongside  what a voluntary payment was paid ON — the thing a tip
     *                                        accompanied, which it otherwise has no way of naming
     * @param  ?PlaceOfSupplyRule  $placeOfSupply  where the supply was taxed
     * @param  ?TaxRateCategory  $rateCategory  which rate band it fell in
     * @param  ?TaxExemptionReason  $exemptionReason  why no tax was charged, where none was. Stated rather
     *                                                than inferred: a renderer can only infer one of the
     *                                                two exempt shapes, and telling a recipient to
     *                                                reverse-charge an export is not a cosmetic error.
     * @param  ?CarbonImmutable  $deliveredOn  when the supply was actually made — EN 16931 BT-72. Supplied
     *                                         and never derived: a term billed in advance would otherwise
     *                                         state a delivery in the future on a document dated before it.
     * @param  ?string  $destinationCountry  where the buyer was, at the grain the obligation is owed at
     * @param  ?string  $destinationSubdivision  and at the finer grain, where one is owed. Absent is
     *                                           counted as unknown rather than attributed somewhere
     *                                           plausible.
     */
    public function __construct(
        public ?TaxArchetype $archetype = null,
        public ?TaxArchetype $soldAlongside = null,
        public ?PlaceOfSupplyRule $placeOfSupply = null,
        public ?TaxRateCategory $rateCategory = null,
        public ?TaxExemptionReason $exemptionReason = null,
        public ?CarbonImmutable $deliveredOn = null,
        public ?string $destinationCountry = null,
        public ?string $destinationSubdivision = null,
    ) {}

    /**
     * The empty set of characteristics — a caller that knows none of them.
     *
     * Named rather than left as `new SupplyTaxCharacteristics()` at each call site, so that a document
     * issued with nothing known says so deliberately instead of by an argument list simply ending.
     */
    public static function unknown(): self
    {
        return new self;
    }
}
