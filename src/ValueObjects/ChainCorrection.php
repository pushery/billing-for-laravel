<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use InvalidArgumentException;
use Pushery\Billing\Enums\SettlementDocumentType;

/**
 * What a buyer refund changes on BOTH links of a commission-chain sale — the outbound supply to the buyer
 * and the inbound supply from the creator — as one answer, computed in one pass.
 *
 * The reason this is one object rather than two calls is the failure it exists to prevent. A refund that
 * corrects only the buyer's link leaves the input tax deducted on the creator's link standing, in full,
 * forever: real money, on every refunded transaction, with nothing red anywhere. The two corrections are not
 * related steps that a caller may sequence — they are one event seen from two sides, and separating them is
 * how the second one goes missing.
 *
 * Every amount here is a POSITIVE magnitude describing a REDUCTION, the same rule the correcting document
 * follows: a correction is not a negative invoice, it is a document whose nature inverts the meaning. A
 * caller that wants signed figures negates them itself, at the point where the sign has a meaning.
 *
 * `reverseChargeTax` is the amount a foreign creator's supply reverses onto the platform. It appears on both
 * sides of the platform's own return and nets to zero, which is exactly why it is carried separately rather
 * than folded into `inboundInputTax`: an amount that reverses is not an amount that was deducted, and adding
 * it to the deduction would understate the liability correction by its full value.
 *
 * `inboundDocument` is the document type the ORIGINAL creator-side document had, or null when the creator's
 * standing was never established and no document was ever issued. It is what tells the document layer
 * whether a creator-side correction is due at all, and which series it draws from — never re-derived from
 * today's standing, which may have moved since the supply.
 */
final readonly class ChainCorrection
{
    public function __construct(
        /** What the buyer is given back, gross — the event that drives everything else. */
        public Money $buyerRefund,
        /** The reduction of the outbound taxable base (the buyer's net). */
        public Money $outboundNet,
        /** The reduction of the outbound tax the platform owes on the sale. */
        public Money $outboundTax,
        /** The reduction of the cost the creator's supply represented — the payout, never the buyer's net. */
        public Money $inboundExpense,
        /** The reduction of deductible input tax. Zero unless the creator's document actually stated tax. */
        public Money $inboundInputTax,
        /** The self-assessed tax that reverses on both sides and nets to zero. Zero unless it reverses. */
        public Money $reverseChargeTax,
        /** What comes back from the creator: the expense plus whatever tax was paid through with it. */
        public Money $merchantClawback,
        /** The platform's own commission on the refunded part, given back with it. */
        public Money $commissionReturned,
        /** The document type of the creator-side original, or null when the standing was a hold. */
        public ?SettlementDocumentType $inboundDocument,
    ) {
        foreach ([
            'buyerRefund' => $buyerRefund,
            'outboundNet' => $outboundNet,
            'outboundTax' => $outboundTax,
            'inboundExpense' => $inboundExpense,
            'inboundInputTax' => $inboundInputTax,
            'reverseChargeTax' => $reverseChargeTax,
            'merchantClawback' => $merchantClawback,
            'commissionReturned' => $commissionReturned,
        ] as $name => $amount) {
            if ($amount->isNegative()) {
                throw new InvalidArgumentException(
                    "A correction states reductions as positive magnitudes; {$name} was negative."
                );
            }
        }

        if ($inboundInputTax->isPositive() && $reverseChargeTax->isPositive()) {
            throw new InvalidArgumentException(
                'A supply cannot both have yielded a deduction and have reversed onto the recipient — those '
                .'are two mutually exclusive answers, and counting both would correct the same tax twice.'
            );
        }

        // The proof that the two links were corrected consistently, checked here rather than in a test the
        // production path does not run: what the buyer got back, less what the creator returns, less the
        // change in what the platform owes the revenue office, is the margin the platform gave up. The two
        // sides of this are computed by different routes — one through the tax treatment of each link, the
        // other through the commission on the remainder — so agreement is evidence rather than a tautology.
        $reconciled = $buyerRefund->minus($merchantClawback)->minus($this->liabilityCorrection());

        if (! $reconciled->equals($commissionReturned)) {
            throw new InvalidArgumentException(
                'The correction does not reconcile: refunding '.$buyerRefund->format().' while reclaiming '
                .$merchantClawback->format().' and correcting the liability by '
                .$this->liabilityCorrection()->format().' returns '.$reconciled->format()
                .' of margin, but the commission on the refunded part is '.$commissionReturned->format().'.'
            );
        }
    }

    /**
     * The reduction of what the platform owes the revenue office.
     *
     * Output tax no longer owed, less input tax no longer deductible. A reverse-charged supply contributes
     * nothing: its tax was both owed and deducted, so removing both leaves the liability where it was, and
     * the full outbound correction stands.
     */
    public function liabilityCorrection(): Money
    {
        return $this->outboundTax->minus($this->inboundInputTax);
    }

    /** Whether a creator-side correcting document is due — false when the original was a hold. */
    public function correctsInboundDocument(): bool
    {
        return $this->inboundDocument instanceof SettlementDocumentType;
    }
}
