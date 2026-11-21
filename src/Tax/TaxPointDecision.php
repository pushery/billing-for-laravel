<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Carbon\CarbonImmutable;
use Pushery\Billing\Enums\TaxPointBasis;

/**
 * When a supply became taxable, and the rule that decided it.
 *
 * The two travel together because the date alone cannot be checked. Recomputing a tax point years later
 * applies today's configuration to a sale made under a different one — so a reviewer who finds a different
 * month has learned nothing: the original may have been wrong, or the rule may have changed since.
 * With the basis frozen beside the date, the same reviewer can tell those apart in one look.
 *
 * `taxedAhead` is here rather than left to the caller because it is the question a document has to be able
 * to answer about itself. A period taxed months before it is rendered looks like an ordinary period on
 * every line of paper it appears on; this is what lets an issuer say otherwise.
 *
 * `rateOn` is the day whose rate the supply carries. It is the tax point, except in a country that applies the
 * rate in force when a supply is carried out, where it is the period's last day: the tax still arises on the
 * tax point there, and only the rate is read later.
 */
final readonly class TaxPointDecision
{
    public function __construct(
        /** The date whose period the tax belongs to. */
        public CarbonImmutable $on,
        /** The rule that produced that date. */
        public TaxPointBasis $basis,
        /** Whether the tax falls due before the service period it belongs to has begun. */
        public bool $taxedAhead,
        /** The day whose rate the supply carries, or null where that is the tax point itself. */
        public ?CarbonImmutable $rateOn = null,
    ) {}
}
