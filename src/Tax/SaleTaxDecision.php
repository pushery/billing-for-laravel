<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Carbon\CarbonImmutable;
use Pushery\Billing\Contracts\TaxCalculator;
use Pushery\Billing\Enums\PlaceOfSupplyRule;
use Pushery\Billing\Enums\TaxArchetype;
use Pushery\Billing\Enums\TaxExemptionReason;
use Pushery\Billing\Exceptions\GrossPriceNotSplittable;
use Pushery\Billing\Exceptions\ProductNotClassified;
use Pushery\Billing\Marketplace\ProductClassifier;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\ServicePeriod;
use Pushery\Billing\ValueObjects\SupplyPlacement;
use Pushery\Billing\ValueObjects\TaxContext;

/**
 * The one place a sale's tax is decided, returning every fact a document must freeze.
 *
 * ## Why this class exists at all
 *
 * Everything it composes was already built, tested and correct — and none of it was ever called. The rate
 * calculator had no call site in the package; the place resolver was reached only from tests; no issuing
 * path ever wrote which country a sale was taxed in. Meanwhile every document took its rate as a bare
 * integer from whoever happened to be calling.
 *
 * That arrangement has a specific consequence, and it is worse than "a feature is missing": every guard the
 * package contains — the destination rate, the reduced band, the reverse-charge zero, the refusal of an
 * unknown country — is bypassed the moment somebody passes `1900` along. The machinery was not wrong. It
 * was not consulted.
 *
 * ## What it returns, and why all of it together
 *
 * A rate on its own is not enough to issue a document or file a return. Which country the tax belongs to,
 * whether the buyer accounts for it instead, and whether the sale belongs in a cross-border consumer
 * scheme are separate answers that travel with it — and separated, they drift. A sale can carry a perfectly
 * correct rate and still be reported into a scheme it does not belong to, which corrupts a whole return
 * rather than one line, with nothing on the document looking wrong.
 *
 * ## It decides; it does not remember
 *
 * Nothing here writes to the database. The caller freezes what it returns onto the document, because the
 * document is what a reader validates years later — and a decision that lives only in a service is a
 * decision nobody can audit.
 */
final readonly class SaleTaxDecision
{
    public function __construct(
        private TaxCalculator $calculator,
        private ProductClassifier $classifier,
        private PlaceOfSupplyResolver $places,
        private TaxPoint $taxPoint,
    ) {}

    /**
     * Decide the tax on one sale.
     *
     * @param  Money  $net  the amount before tax
     * @param  TaxContext  $buyer  what is known about the buyer: country, VAT id and whether it was validated
     * @param  ServicePeriod|null  $period  the period supplied, where the sale has one; a one-off has none
     * @param  CarbonImmutable|null  $paidOn  when the money arrived, which decides the point under a receipt basis
     * @param  TaxArchetype|null  $soldAlongside  for an archetype that takes its treatment from something
     *                                            else — a tip, a fan-chosen top-up — what it was paid ON
     */
    public function decide(
        TaxArchetype $archetype,
        Money $net,
        TaxContext $buyer,
        ?ServicePeriod $period = null,
        ?CarbonImmutable $paidOn = null,
        ?TaxArchetype $soldAlongside = null,
    ): SaleTaxFacts {
        // The product's own rule first — a download is placed differently from a live one-to-one session —
        // and then the buyer's status has its say over it. The order matters: reading the product last would
        // give a consumer answer for a validated business.
        //
        // Asked through the CLASSIFIER rather than the taxonomy directly, so that an archetype which
        // delegates gets its answer from what it was sold alongside. The merge belongs there and only there:
        // a second copy of "take the delegated cells from the reference" is the one quantity, two derivations
        // that this package keeps paying for. The classifier also raises the same refusal this method used to
        // raise itself when a delegating archetype arrives without its reference.
        $placeCell = $this->classifier->classify($archetype, $soldAlongside)->placeOfSupply;

        // ONE archetype is refused here, and it used to be two. That change is the point of this block.
        //
        // A tip is now ANSWERABLE: `$soldAlongside` is the signature change the comment that stood here used
        // to defer to "the ticket that owns the delegation", and it takes its placement from what it was paid
        // on. Its refusal did not disappear, it MOVED — the classifier resolves a delegating archetype from
        // its reference or refuses, and a reference that itself delegates is refused one level down. So a
        // delegated cell can no longer come back out, and a branch for it here would be dead code wearing a
        // guard's clothes, which the next reader would mistake for the place the rule lives.
        //
        // A multi-purpose voucher stays refused unconditionally, and no reference argument can change that:
        // it has no treatment AT ALL until redemption, so answering would mean inventing a redemption that
        // has not happened. Reading one out of the cell used to end in a `LogicException` from deep inside a
        // value object — a message written for whoever maintains this package, surfacing to whoever merely
        // called `decide()`. It still refuses; the refusal is now this package's own and says what to do.
        if ($placeCell->isDeferred()) {
            throw ProductNotClassified::deferredUntilRedemption($archetype->value);
        }

        $productRule = $placeCell->value();

        // The taxonomy cell is deliberately untyped — it carries whatever a jurisdiction put in it. A cell
        // that does not hold a place rule is a profile defect, and treating it as "destination" would hide
        // that defect behind a plausible answer on every invoice.
        if (! $productRule instanceof PlaceOfSupplyRule) {
            throw ProductNotClassified::forPlaceOfSupply($archetype->value);
        }

        $placement = $this->places->place($productRule, $buyer);
        $context = $this->places->taxContextFor($productRule, $buyer);

        // Decided BEFORE the rate is asked for, which is the whole change. The tax point was already being
        // worked out here — it just arrived after the calculation and went straight into the facts, so the
        // rate came from the moment the code ran rather than the moment the supply was taxed. The law binds
        // it the other way round (Art. 93 VAT Directive), and on an installation with a rate history the two
        // are different numbers.
        //
        // Absent a period there is nothing to place — a one-off is taxed when it happens — and the context
        // then carries null, which takes the undated answer rather than inventing today.
        $taxPoint = $period instanceof ServicePeriod
            ? $this->taxPoint->decideFor($period, $paidOn ?? $period->from)
            : null;

        $tax = $this->calculator->calculate($net, $context->at($taxPoint?->on));

        return new SaleTaxFacts(
            tax: $tax,
            placement: $placement,
            // The country the DOCUMENT states, which is the one the tax belongs to — not necessarily the
            // buyer's. A seller-placed supply to a foreign consumer is taxed at home, and a document naming
            // the buyer's country there would report it into a return that never owed it.
            country: $this->places->documentCountryFor($productRule, $buyer),
            rateBps: $this->rateBpsOf($net, $tax),
            rateCategory: $context->rateCategory,
            exemption: $this->exemptionFor($net, $tax, $placement, $buyer),
            // Decided here rather than left to the caller, for the same reason the rate is: this is the one
            // place a sale's tax is settled, and a tax point worked out somewhere else is a second answer to
            // a question that must have exactly one. It is the SAME value the rate was asked for above,
            // computed once — a second call here would be a second answer to that same question.
            taxPoint: $taxPoint,
        );
    }

    /**
     * Decide the tax on a sale whose BUYER-FACING TOTAL is what was chosen, not its net.
     *
     * A tip and a pay-what-you-want price arrive gross: the fan picks what they will pay, and the net is
     * whatever is left once the tax is taken out. Every other path in this package prices the other way
     * round, from a net the seller sets, and the difference is not cosmetic — inverting a rate is a
     * different operation from applying one, and doing it ad hoc at each call site is how two receipts for
     * the same amount stop agreeing.
     *
     * ## Why the rate is read back rather than looked up
     *
     * There is no rate table to consult here. `rateBps` on the facts is DERIVED — the calculator is asked
     * for an amount and the rate is the quotient. So the rate for this sale is obtained the only way it can
     * be: ask the calculator once, on the gross, purely to learn the slope; then split the real gross at
     * that slope. The probe's tax is discarded — it answers a question about the wrong amount.
     *
     * ## What is preserved, and what is checked
     *
     * `net + tax == gross`, exactly, always. That is the invariant with a person behind it: the fan agreed
     * to pay this total and a document whose parts do not add up to it is visibly wrong. `Money::baseFromMarkup`
     * guarantees it by taking the tax as the remainder rather than computing it a second time.
     *
     * What is then CHECKED is whether the regime agrees: charged on the resulting net, does it produce the
     * tax the split implies? One minor unit of slack is allowed, because some totals genuinely have no exact
     * inverse in whole cents. Anything wider means the rate does not scale with the amount, and the whole
     * approach is void for that regime — so it refuses rather than shipping a number that looks right.
     *
     * @param  Money  $gross  what the buyer chose to pay, tax included
     * @param  TaxArchetype|null  $soldAlongside  what a delegating archetype was paid on
     */
    public function decideOnGross(
        TaxArchetype $archetype,
        Money $gross,
        TaxContext $buyer,
        ?ServicePeriod $period = null,
        ?CarbonImmutable $paidOn = null,
        ?TaxArchetype $soldAlongside = null,
    ): SaleTaxFacts {
        // The probe. Its own tax is meaningless — it is the tax on a number nobody is paying — but the rate
        // it carries is the regime's slope for this buyer and this product, which is the one thing needed.
        $slope = $this->decide($archetype, $gross, $buyer, $period, $paidOn, $soldAlongside)->rateBps;

        // Nothing to strip out: an exempt, reverse-charged or untaxed sale has a net equal to its total, and
        // `baseFromMarkup(0)` would say the same thing at more cost.
        if ($slope === 0) {
            return $this->decide($archetype, $gross, $buyer, $period, $paidOn, $soldAlongside);
        }

        [$net, $impliedTax] = $gross->baseFromMarkup($slope);

        $facts = $this->decide($archetype, $net, $buyer, $period, $paidOn, $soldAlongside);

        // The one-cent tolerance is a property of inverting a rounded function, not a defect: for some
        // totals no whole-cent net reproduces them exactly. A wider gap is a statement about the regime —
        // that its rate is not linear in the amount — and then no net answers this question at all.
        if (abs($facts->tax->minorUnits - $impliedTax->minorUnits) > 1) {
            throw new GrossPriceNotSplittable($gross, $slope, $impliedTax, $facts->tax);
        }

        // The SPLIT's tax is what travels on, not the recomputed one. They differ by at most that cent, and
        // only one of the two adds back up to what the fan actually paid.
        return $facts->withTax($impliedTax);
    }

    /**
     * Why this sale carried no tax, or null when it carried some.
     *
     * The calculator answers with an amount, and `Money::zero` is the same object whether the buyer accounts
     * for the tax or the supply is placed outside the union entirely. Those are different sentences on a
     * document and different lines in a return, so the reason is decided here — from the placement and the
     * profile's own union list — rather than inferred downstream from a number that cannot carry it.
     *
     * A zero net is not an exemption. Something free is taxable at nothing; saying it was exempt would put
     * an exemption note on a document that has no supply to exempt.
     */
    private function exemptionFor(Money $net, Money $tax, SupplyPlacement $placement, TaxContext $buyer): ?TaxExemptionReason
    {
        if ($net->isZero() || ! $tax->isZero()) {
            return null;
        }

        // Asked first because it is the narrower fact: a validated business elsewhere in the union accounts
        // for the tax itself. Checking the union membership first would call that supply an outbound one.
        // Would this regime have taxed anything at all? An installation configured for no tax returns zero
        // for every sale, and reading those zeros as exemptions would print a legal claim the operator never
        // made — "supplied outside the union" on a shop that simply does not compute VAT. So the question is
        // asked of the calculator itself, with the plainest domestic sale there is: if THAT is also zero, the
        // zero here says nothing about this supply, and there is no exemption to name.
        //
        // Asked rather than inferred from configuration, because the calculator is what actually answered.
        if (! $this->regimeCharges($net)) {
            return null;
        }

        // Asked first because it is the narrower fact: a validated business elsewhere in the union accounts
        // for the tax itself. Checking the union membership first would call that supply an outbound one.
        if ($placement->reverseCharge) {
            return TaxExemptionReason::ReverseCharge;
        }

        return $this->places->insideUnion($buyer->countryCode)
            ? null
            : TaxExemptionReason::SuppliedOutsideTheUnion;
    }

    /**
     * Whether this tax regime charges anything on an ordinary domestic consumer sale.
     *
     * The probe is deliberately the least exceptional sale the installation can make: its own country, a
     * consumer, no VAT id. Anything that comes back zero from THAT is a regime that does not tax, not a
     * supply that was relieved.
     */
    private function regimeCharges(Money $net): bool
    {
        $home = $this->places->sellerCountry();

        if ($home === null) {
            return false;
        }

        return ! $this->calculator->calculate($net, new TaxContext(countryCode: $home))->isZero();
    }

    /**
     * The rate the sale actually carried, in basis points, derived from what was charged.
     *
     * Taken from the two amounts rather than asked of the calculator a second time. A second question is a
     * second chance to get a different answer — and the figure that belongs on the document is the one the
     * buyer was charged, not the one a table would give if asked again.
     */
    private function rateBpsOf(Money $net, Money $tax): int
    {
        if ($net->isZero()) {
            return 0;
        }

        return (int) round($tax->minorUnits * 10_000 / $net->minorUnits);
    }
}
