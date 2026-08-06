<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\Enums\SupplyRegime;
use Pushery\Billing\Enums\TaxArchetype;
use Pushery\Billing\Marketplace\ConfigSupplyRegimeResolver;

/**
 * A jurisdiction profile that says which supply regime a product archetype COMPELS, overriding the
 * platform's configured default.
 *
 * ## Why this is asked for rather than known
 *
 * The shipped resolver used to carry one such rule itself: goods sold between private people were forced to
 * intermediation, on the reasoning that the platform cannot be reselling something it never owned when
 * neither party is in business. The reasoning is sound and the placement was not. The FACT — that the
 * platform owned nothing — is jurisdiction-neutral; the conclusion drawn from it, that this makes the sale
 * an arranged one rather than a resold one, is a legal characterization, and a legal characterization
 * sitting in a neutral core is one jurisdiction's answer wearing the costume of a general one.
 *
 * That is the same mistake the reporting-rate seam was built to avoid, in the same package, and stated in
 * almost these words there: a rule that happens to be uniform across one union is still that union's rule.
 * A regime decides which documents a sale produces and whose turnover it is, so getting it wrong by
 * inheritance is expensive in a way a wrong rate is not.
 *
 * ## What a profile that does not implement this means
 *
 * That no archetype compels anything, and the platform's configured default stands. This is deliberately
 * NOT a refusal, and the difference from the exchange-rate seams is worth stating: there, a missing profile
 * means no rate exists to freeze and the alternative would be inventing one. Here an answer already exists
 * and the operator wrote it — `billing.marketplace.regime.default` is an explicit statement, not a guess.
 * The opt-in allow-list beside it still refuses any regime the platform has not said it operates, so the
 * failure mode this guards against — falling into arranging other people's sales because a product was
 * classified in a way nobody looked at — stays closed either way.
 *
 * @see ConfigSupplyRegimeResolver for the resolver that consults this
 */
interface SuppliesArchetypeRegimes
{
    /**
     * The regime this archetype compels in this jurisdiction, or null when it compels none and the
     * platform's configured default is the answer.
     */
    public function regimeForArchetype(TaxArchetype $archetype): ?SupplyRegime;
}
