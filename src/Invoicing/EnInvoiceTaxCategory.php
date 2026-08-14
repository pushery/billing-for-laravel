<?php

declare(strict_types=1);

namespace Pushery\Billing\Invoicing;

use Pushery\Billing\Enums\TaxArchetype;
use Pushery\Billing\Enums\TaxExemptionReason;
use Pushery\Billing\Exceptions\ContradictoryExemption;
use Pushery\Billing\Tax\UnionMembership;

/**
 * The EN 16931 tax category for one supply, and the exemption code that must travel with it.
 *
 * ## Why this is one class and not a ternary in each writer
 *
 * Both writers used to derive the category themselves, from the same chain of two booleans:
 * `$reverseCharge ? 'AE' : ($exempt ? 'E' : ($rate > 0 ? 'S' : 'Z'))`. Two copies of a rule are two places
 * it can drift, and the drift has no symptom — each document is internally consistent, and only a reader
 * comparing a UBL and a CII rendering of the SAME invoice would ever see them disagree.
 *
 * ## What the two booleans could not say
 *
 * They reach four categories, and the missing ones are not edge cases:
 *
 * - **`G`** — an export of goods to a third country used to render as `Z`. Those are different statements.
 *   `Z` says "the supplier taxed this at 0%"; `G` says "this was exported, no tax charged". Different
 *   exemption code, different treatment at the recipient, and the difference is not recoverable from the
 *   document afterwards.
 * - **`K`** — an exempt intra-community supply of GOODS used to render as `AE`. `AE` is the services term;
 *   goods take `K` with VATEX-EU-IC.
 *
 * The distinction both need is goods-versus-services, and that is a fact about the product, which the
 * document already freezes as `tax_archetype`. So the category is decided from the frozen exemption reason
 * AND the frozen archetype — never from the amount, which is the same zero in every one of these cases.
 *
 * - **`O`** — a SERVICE placed outside the union is outside the SCOPE of VAT, not taxed at zero. It used to
 *   render as `Z`, which says the tax reached the supply and the rate was nothing; `O` says it never
 *   reached it. Introducing it required enforcing BR-O-11 first, which is why it arrived later than the
 *   others: the category is **exclusive**, so an invoice carrying an O breakdown may carry no other, and
 *   the BR-O-* rules forbid it stating a tax amount or a rate at all. A taxed band on such a document is
 *   refused here rather than downgraded, because the business answer is two documents and a downgrade
 *   would file a supply frozen as outside the scope as though it had been taxed.
 */
final readonly class EnInvoiceTaxCategory
{
    private function __construct(
        /** The EN 16931 category code (BT-118 / BT-151). */
        public string $code,
        /** The VATEX code (BT-121), where the category has one. */
        public ?string $vatexCode,
        /** The default wording for BT-120, used when the document carries no note of its own. */
        public string $reason,
    ) {}

    /**
     * Decide the category for one supply.
     *
     * @param  bool  $exempt  the supply is exempt for a reason the reason enum does not model — a
     *                        small-business relief, for instance, which is exempt (E) and NOT zero-rated (Z)
     * @param  float  $rate  the rate actually charged, which decides only between S and Z once nothing above applies
     */
    public static function for(?TaxExemptionReason $exemption, ?TaxArchetype $archetype, bool $exempt, float $rate, ?string $destinationCountry = null): self
    {
        // A supply frozen as leaving the union, stated as going to a member of it, is a contradiction in the
        // document's OWN data. Refused rather than rendered: whichever of the two is wrong, one of them is,
        // and a document that asserts both is worse than no document — it would claim an exemption its own
        // destination disproves, and only an auditor comparing the two fields would ever notice.
        if ($exemption === TaxExemptionReason::SuppliedOutsideTheUnion
            && $destinationCountry !== null
            && $destinationCountry !== ''
            && in_array(strtoupper($destinationCountry), array_map(strtoupper(...), UnionMembership::members()), true)
        ) {
            throw ContradictoryExemption::exportInsideTheUnion($destinationCountry);
        }

        $goods = self::isGoods($archetype);

        // Asked before `$exempt`, because a reverse charge IS an exemption and would otherwise be flattened
        // into E — losing both the category and the buyer's obligation to account for the tax.
        if ($exemption === TaxExemptionReason::ReverseCharge) {
            return $goods
                ? new self('K', 'VATEX-EU-IC', 'Intra-Community supply')
                : new self('AE', 'VATEX-EU-AE', 'Reverse charge');
        }

        if ($exemption === TaxExemptionReason::SuppliedOutsideTheUnion && $goods) {
            return new self('G', 'VATEX-EU-G', 'Export outside the EU');
        }

        // A SERVICE placed outside the union is outside the SCOPE of VAT, which is a different statement
        // from "taxed at zero". `O` says the tax does not reach this supply at all; `Z` says it reached it
        // and the rate happened to be nothing. Only the first is true here, and the difference is not
        // recoverable from the document later.
        if ($exemption === TaxExemptionReason::SuppliedOutsideTheUnion) {
            // BR-O-11 makes `O` EXCLUSIVE: an invoice carrying an O breakdown may carry no other category,
            // and the BR-O-* rules forbid it stating a tax amount or a rate at all. A taxed band on such a
            // document is therefore not a category question but a document that cannot exist — and emitting
            // it would produce something a conformant validator rejects outright, which is worse than an
            // imprecise category because it cannot be filed at all.
            //
            // The business answer is two documents, not one, and that is why this refuses rather than
            // silently downgrading the band to Z: a downgrade would file a supply the platform froze as
            // outside the scope of tax as though it had been taxed.
            if ($rate > 0) {
                throw ContradictoryExemption::taxedSupplyOutsideTheScope($rate);
            }

            // No VATEX code: BT-121 belongs to the exemption categories, and a supply outside the scope is
            // not exempt — there is nothing to be relieved from.
            return new self('O', null, 'Services outside scope of tax');
        }

        // A small-business relief is E, like any other exemption, and carries NO VATEX code on purpose.
        // BR-E-10 accepts the reason text alone, and the text is where the ground actually gets named —
        // a code guessed from the published list would be a claim about which article relieves this
        // supplier, which is precisely the fact the platform is not in a position to assert for them.
        if ($exemption === TaxExemptionReason::DomesticSmallBusiness) {
            return new self('E', null, 'Exempt under the domestic small-business scheme');
        }

        if ($exemption === TaxExemptionReason::UnionSmallBusinessScheme) {
            return new self('E', null, 'Exempt under the small-business scheme of another member state');
        }

        // An exempt supply is E, and E is NOT Z: a relief from VAT is not a rate of zero, and EN 16931's
        // BR-E-* rules differ from BR-Z-*. BR-E-10 accepts the reason text alone, without a VATEX code.
        if ($exempt) {
            return new self('E', null, 'Tax exempt');
        }

        return $rate > 0
            ? new self('S', null, '')
            : new self('Z', null, '');
    }

    /**
     * Whether an archetype is a supply of goods rather than a service.
     *
     * Here rather than on `TaxArchetype`, and that placement is enforced: `ProductTaxonomyTest` asserts the
     * enum carries NO accessors, because an accessor on it would hard-code one jurisdiction's answer into a
     * fact that every jurisdiction shares, and a consumer elsewhere could not replace it without replacing
     * the enum.
     *
     * Goods-versus-services is not one of those varying answers — a download is a service wherever it is
     * sold — so it is read here, at the one place that needs it, rather than pushed into the profile
     * contract as a sixth taxonomy cell every profile would have to answer identically. If a jurisdiction
     * ever needs to disagree, this is the seam to move, and moving it means adding that cell.
     */
    private static function isGoods(?TaxArchetype $archetype): bool
    {
        return $archetype === TaxArchetype::ConsumerGoods;
    }

    /** Whether this category must carry an exemption reason on the document band. */
    public function needsReason(): bool
    {
        return $this->reason !== '';
    }
}
