<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Pushery\Billing\Enums\TaxArchetype;

/**
 * What a seller did over a period, in the terms a reporting rule asks about.
 *
 * Deliberately not free text. A rule that reads a description decides differently depending on how somebody
 * phrased a product, and the decision here is one a regulator will later ask to see reproduced.
 *
 * The counts and the amount come from elsewhere — this only carries them, so the rule that reads them stays
 * a pure function of its inputs and can be replayed for any period with the same answer.
 *
 * ## Where the archetype comes from — and this paragraph used to say the opposite
 *
 * It said the package records no archetype, that looking for a column to read one from was time wasted, and
 * that adding one would reverse a decision. That was true of the design it was written for and is not true
 * of the code: the settlement document freezes `tax_archetype`, and {@see SettlementGrossInflowCounter}
 * selects it off exactly those rows to split a period by it. The sibling counter's docblock had already been
 * corrected for the same overstatement; this one had not, so a reader of the published package was being
 * sent to their own catalog for a fact the package hands them.
 *
 * What is genuinely the CONSUMER's is the product catalog itself — which product carries which archetype.
 * What the package holds is what a settled document FROZE, which is a different and narrower thing: the
 * answer as it stood when the money moved, immune to a later reclassification of the product.
 *
 * ## A voluntary payment carries a second archetype, and the rule needs both
 *
 * A tip has no treatment of its own; the taxonomy delegates all of it — reportability included — to whatever
 * the tip accompanied. So the archetype alone cannot be asked, and it is not: the reference travels with it
 * and the two readers below resolve through it. Answering from the tip would classify every tip as
 * standardized, and a tip on commissioned work would go unreported — the under-reporting direction, which is
 * the one the statute sanctions.
 */
final readonly class SellerActivity
{
    public function __construct(
        public ?TaxArchetype $archetype,
        /** How many sales over the period the rule measures. */
        public int $salesCount,
        /** What the seller was paid over that period. */
        public Money $compensation,
        /**
         * Says this WAS commissioned when the archetype cannot say so — a commission the catalog never
         * classified. It can only ever widen the answer; see {@see individuallyCommissioned()} for why it
         * is not simply the old boolean back.
         */
        public bool $commissionedRegardless = false,
        /**
         * What a voluntary payment was paid ON, for an archetype that has no answers of its own.
         *
         * Null for every ordinary sale, and null is right there: an archetype that answers for itself needs
         * no reference and must not be given one. It is null for a tip too when the document predates the
         * column — see {@see effectiveArchetype()} for why that stays unresolved rather than guessed.
         */
        public ?TaxArchetype $soldAlongside = null,
    ) {}

    /** Whether what was sold is goods rather than something delivered digitally. */
    public function isGoods(): bool
    {
        return $this->effectiveArchetype() === TaxArchetype::ConsumerGoods;
    }

    /**
     * The archetype whose consequences actually apply — a tip's reference, or the archetype itself.
     *
     * The delegating set is a jurisdiction's answer and lives in the taxonomy, which states it for the tip
     * row as `reportable: delegated` in as many words. It is restated here as an exhaustive match rather
     * than read from the taxonomy, because this is a value object and injecting a profile into it would make
     * the rule's inputs stop being pure — the one property that lets a period be replayed years later to the
     * same answer. `DelegatingArchetypesAgreeTest` holds the two statements together, so the restatement
     * cannot drift into a second, quieter truth.
     *
     * ## An unresolved delegation stays unresolved
     *
     * A tip with no reference returns the TIP, not a substitute. That is deliberately not the reference's
     * absence being read as "sold alone": every tip settled before this column existed is in that state, and
     * both ways of resolving it are wrong on real data. Answering "standardized" would clear a reporting
     * duty on no evidence; answering "commissioned" would file a seller whose tips were on downloads. The
     * archetype comes back as it is, and the caller sees a tip.
     */
    public function effectiveArchetype(): ?TaxArchetype
    {
        if (! $this->soldAlongside instanceof TaxArchetype) {
            return $this->archetype;
        }

        return match ($this->archetype) {
            TaxArchetype::Tip => $this->soldAlongside,
            TaxArchetype::Download, TaxArchetype::Subscription, TaxArchetype::Ebook,
            TaxArchetype::BundleWithAudioVideo, TaxArchetype::Livestream, TaxArchetype::CustomOneToOne,
            TaxArchetype::Voucher, TaxArchetype::ConsumerGoods => $this->archetype,
            // Nobody said what this was. A reference cannot rescue that: it says what the payment accompanied,
            // not what the payment was, and the two are only the same question for an archetype that delegates.
            null => null,
        };
    }

    /**
     * Whether it was commissioned for one buyer, rather than sold off the shelf.
     *
     * DERIVED from the archetype, which already says it, with one flag that can only ever widen the answer.
     *
     * It used to be an ordinary boolean sitting BESIDE the archetype — one fact written twice, statable in
     * contradiction, with nothing to catch it. The direction that contradiction fell is what made it worth
     * removing rather than validating: the reporting rule asks this FIRST and returns on it, so an activity
     * carrying `CustomOneToOne` with the boolean left at its default classified as standardized, and a
     * seller who had to be reported was not. A default that decides a reporting duty is the wrong kind of
     * default.
     *
     * The flag survives because deleting it would have taken a real case with it: a commission the catalog
     * never classified is still a commission, and the duty turns on the commission rather than on the
     * classification. What it cannot do is the other direction — an explicit `false` beside `CustomOneToOne`
     * would be the exact failure this change exists to end, so the flag ORs rather than overrides. Saying
     * "this was commissioned" is a statement somebody makes; saying "this was not" is what a forgotten
     * argument looks like, and the two must not have the same power.
     *
     * The match lives HERE and not on the enum, and that is not where it was first written. An accessor on
     * `TaxArchetype` reads better and is wrong: the archetypes are a fact about commerce and what follows
     * from one is a jurisdiction's answer, so an answer on the enum is one a consumer elsewhere cannot
     * replace without replacing the fact. `ProductTaxonomyTest` holds that line and caught this. Beside
     * `isGoods()` is where a reading of an archetype belongs — the same shape, one file, replaceable.
     *
     * Exhaustive on purpose: a new case makes this fail static analysis rather than defaulting to `false`,
     * which would answer for a case nobody has considered, in the under-reporting direction.
     */
    public function individuallyCommissioned(): bool
    {
        if ($this->commissionedRegardless) {
            return true;
        }

        return match ($this->effectiveArchetype()) {
            TaxArchetype::CustomOneToOne => true,
            TaxArchetype::Download, TaxArchetype::Subscription, TaxArchetype::Ebook,
            TaxArchetype::BundleWithAudioVideo, TaxArchetype::Livestream, TaxArchetype::Tip,
            TaxArchetype::Voucher, TaxArchetype::ConsumerGoods => false,
            // No archetype at all is not "not commissioned" — it is "nobody said". It reads as false here
            // because the flag above is how a caller says otherwise, and inventing a commission from an
            // absence would report a seller on no evidence.
            null => false,
        };
    }
}
