<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Carbon\CarbonImmutable;
use Pushery\Billing\Exceptions\SettlementTransactionChargeIncomplete;
use Pushery\Billing\Tax\TaxPoint;
use Pushery\Billing\Tax\TaxPointDecision;

/**
 * One settled transaction feeding a monthly collective self-billing document — the input a run turns into a
 * single line.
 *
 * It carries what the input-side matrix needs to price the line (the transaction net, the platform's
 * commission and the supply rate) plus the supply date, which decides two things at once: the creator's
 * standing is read as of that date, and the date (or its month, an admissible time reference) is what the
 * line states as its service time. The description is the line's type and extent of service; a run supplies
 * its own default when none is given.
 */
final readonly class SettlementTransaction
{
    public function __construct(
        public Money $net,
        public PlatformFee $commission,
        public int $supplyRateBps,
        public CarbonImmutable $supplyDate,
        public ?string $description = null,
        /**
         * The period this transaction COUNTS IN, where that is not the period it was supplied in.
         *
         * A third question, kept apart from the two the supply date already answers. Both chain legs must
         * land in the same period: where the buyer's side was taxed on receipt — a term paid up front — the
         * creator's settlement belongs to the month the money arrived, not to each month as it is rendered.
         * Let the legs drift and an input-tax offset opens across the remaining months, in a direction
         * nobody checks and which every individual document agrees with.
         *
         * Null means the ordinary case: supplied and counted in the same period.
         */
        public ?CarbonImmutable $countsIn = null,
        /**
         * The routed charge this transaction settles, as the pair that identifies one.
         *
         * Optional, and absent is the ordinary case: a caller settling something that never went through the
         * routed ledger has no charge to name, and every caller written before this one names none.
         *
         * Both halves or neither. A charge reference is unique only PER PROVIDER — the charge table says so
         * with a composite unique key — so a bare reference could attach this transaction to another driver's
         * sale, and the document would then record that it settled a charge it never mentioned.
         */
        public ?string $chargeProvider = null,
        public ?string $chargeReference = null,
    ) {
        if (($chargeProvider === null) !== ($chargeReference === null)) {
            throw SettlementTransactionChargeIncomplete::make();
        }
    }

    /**
     * The same transaction, taking its counted period from the tax point that decided the OTHER leg.
     *
     * ## The gap this closes, and it is not ergonomics
     *
     * `$countsIn` above says both legs must land in the same period. Nothing made that happen. It is an
     * optional argument a consumer fills in by hand, from a fact the package already computed for the
     * buyer's document and then did not offer — so the drift the field exists to prevent is a matter of
     * remembering, and the failure is silent in the expensive direction: leave it out and `countedOn()`
     * falls back to the supply date, the run settles in the month of supply, and the engine's own refusal
     * says nothing, because the transaction WAS handed to the month it claims.
     *
     * That refusal only ever caught the smaller mistake — a consumer who filled the field in and then
     * grouped by the other date. The larger one, never filling it in, had no arm at all.
     *
     * So the decision travels instead of being retyped. {@see TaxPoint::decideFor()}
     * produces it for the buyer's leg; handing that same object here is what makes "both legs agree" a
     * consequence rather than a convention.
     *
     * ## Why it is a named constructor and not a parameter
     *
     * Appending a required argument to the constructor above would break every existing construction at its
     * call site — a major bump for a correctness helper. This is additive: a consumer opts in, and an
     * existing `new SettlementTransaction(...)` behaves exactly as it did.
     *
     * ## Why the period is recorded only when it actually differs
     *
     * Comparing `Y-m` is not a shortcut — it is the SAME expression `CollectiveSelfBillingEngine` uses to
     * accept or refuse a transaction, so the derivation cannot disagree with the check about what a period
     * is. Where the two dates fall in one month there is nothing to carry, and recording it anyway would
     * make every ordinary transaction look like the special case, costing `null` the meaning the field's
     * own documentation gives it.
     *
     * @param  TaxPointDecision  $taxPoint  the buyer's leg's tax point — the decision, not a bare date
     */
    public static function countingWith(
        TaxPointDecision $taxPoint,
        Money $net,
        PlatformFee $commission,
        int $supplyRateBps,
        CarbonImmutable $supplyDate,
        ?string $description = null,
        // Carried through rather than left to the plain constructor. Without it the two opt-ins would be
        // mutually exclusive: a consumer whose legs need a traveling tax point could never name the charge
        // its settlement belongs to, and the one that most needs both is a term paid up front on a routed sale.
        ?string $chargeProvider = null,
        ?string $chargeReference = null,
    ): self {
        return new self(
            net: $net,
            commission: $commission,
            supplyRateBps: $supplyRateBps,
            supplyDate: $supplyDate,
            description: $description,
            countsIn: $taxPoint->on->format('Y-m') === $supplyDate->format('Y-m') ? null : $taxPoint->on,
            chargeProvider: $chargeProvider,
            chargeReference: $chargeReference,
        );
    }

    /** The period this transaction is assigned to — its own, where it has one, else the supply's. */
    public function countedOn(): CarbonImmutable
    {
        return $this->countsIn ?? $this->supplyDate;
    }
}
