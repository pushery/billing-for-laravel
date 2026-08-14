<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use InvalidArgumentException;
use Pushery\Billing\Enums\SettlementDocumentType;
use Pushery\Billing\Enums\TaxExemptionReason;

/**
 * The tax decision for one creator's supply into a commission-chain sale — the whole answer, and nothing
 * a downstream step has to re-derive.
 *
 * Six things, because "how is this creator's link taxed" has exactly six honest parts: which document to
 * issue, whether it states tax, how much, whether the tax burden reverses onto the recipient instead, what
 * the creator is paid, and whether the supply is EXEMPT. The document engine renders from this; it never
 * re-decides it.
 *
 * `exempt` is the one field a downstream step could not reconstruct: a small business's supply and a
 * standard-rated supply whose tax statement is merely WITHHELD pending validation look identical here — both
 * state no tax on a self-billed invoice — yet one is exempt (rendered EN 16931 category E, with a reason)
 * and the other is a taxable supply not yet stated (not exempt). Only the tax decision knows which, so it
 * says so, rather than leaving the writer to guess exempt from the absence of tax.
 *
 * A hold is the absence of a document: `document` is null, and the creator is paid nothing until their
 * standing is established. It is a first-class state, not an error — a creator whose status was never
 * clarified must not be guessed into the most convenient one.
 *
 * The invariants are enforced here rather than trusted to every caller, because each is a hard line a wrong
 * document would cross silently: a tax statement can appear only on a self-billed invoice; a supply cannot
 * both state its own tax and reverse the burden onto the recipient; and an exempt supply states no tax and
 * does not reverse-charge — exemption is a THIRD, distinct answer to how the link is taxed.
 */
final readonly class InboundTaxTreatment
{
    public function __construct(
        public ?SettlementDocumentType $document,
        public bool $showsTax,
        public Money $taxAmount,
        public bool $reverseChargeToRecipient,
        public Money $payoutAmount,
        public bool $exempt = false,
        /**
         * WHY the supply is relieved, where it is.
         *
         * `exempt` says THAT a relief applies; this says which one, and the document needs both. Without it
         * the issuer freezes "exempt" and the renderer has nothing to name, so BT-120 falls back to a generic
         * phrase that claims a relief without stating its ground — and the column is frozen, so nobody can
         * heal the document afterwards.
         *
         * Null on a supply with no relief, and null is not a defect: a standard-rated band must carry no
         * exemption reason at all (BR-S-*).
         */
        public ?TaxExemptionReason $exemptionReason = null,
    ) {
        if ($showsTax && $document !== SettlementDocumentType::SelfBilledInvoice) {
            throw new InvalidArgumentException(
                'A tax statement may appear only on a self-billed invoice; a settlement note or a hold that '
                .'stated tax would make the recipient owe it.'
            );
        }

        if ($showsTax && $reverseChargeToRecipient) {
            throw new InvalidArgumentException(
                'A supply cannot both state its own tax and reverse the burden onto the recipient — those '
                .'are two mutually exclusive answers to the same question.'
            );
        }

        if ($exemptionReason instanceof TaxExemptionReason && $showsTax) {
            throw new InvalidArgumentException(
                'A supply that states its own tax cannot also name an exemption reason — the reason answers '
                .'why no tax was stated, and a document carrying both would contradict itself.'
            );
        }

        if ($exempt && ($showsTax || $reverseChargeToRecipient)) {
            throw new InvalidArgumentException(
                'An exempt supply states no tax and does not reverse the charge — exemption is a distinct '
                .'answer, not one that also states tax or shifts it to the recipient.'
            );
        }
    }

    /** A creator whose standing is unestablished: no document, and nothing is paid until it is clarified. */
    public static function hold(string $currency): self
    {
        return new self(null, false, Money::zero($currency), false, Money::zero($currency));
    }

    public function isHold(): bool
    {
        return ! $this->document instanceof SettlementDocumentType;
    }
}
