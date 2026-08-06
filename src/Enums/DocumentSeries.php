<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * A numbering series for a document the platform issues itself, rather than one a provider numbered.
 *
 * Four roles the platform mints numbers for — the buyer's receipt, the self-billed invoice to a merchant,
 * the settlement note to a private party, the commission invoice of a genuine intermediation — and a
 * correction series paired to each. A correction draws from its OWN series and references the document it
 * corrects, so a corrected invoice and its correction never share a number and a reader can tell them apart
 * at a glance.
 *
 * These are ROLES, not prefixes. The visible prefix (`G-`, `KF-`, …) is a jurisdiction's choice and lives
 * in config; the enum is what the code branches on, so a typo at a call site cannot invent a ninth series.
 * A consumer elsewhere maps these same roles to their own letters and reads no German mnemonic.
 */
enum DocumentSeries: string
{
    case BuyerReceipt = 'buyer_receipt';
    case SelfBilledInvoice = 'self_billed_invoice';
    case SettlementNote = 'settlement_note';
    case CommissionInvoice = 'commission_invoice';

    case BuyerReceiptCorrection = 'buyer_receipt_correction';
    case SelfBilledInvoiceCorrection = 'self_billed_invoice_correction';
    case SettlementNoteCorrection = 'settlement_note_correction';
    case CommissionInvoiceCorrection = 'commission_invoice_correction';

    /** Whether this series numbers corrections rather than originals. */
    public function isCorrection(): bool
    {
        return $this->corrects() instanceof self;
    }

    /**
     * The original series a correction series belongs to — or null for an original.
     *
     * A correction is never a standalone document: it exists only against an original, so its series is
     * paired to exactly one original's. The pairing lives here so no call site has to hard-code it.
     */
    public function corrects(): ?self
    {
        return match ($this) {
            self::BuyerReceiptCorrection => self::BuyerReceipt,
            self::SelfBilledInvoiceCorrection => self::SelfBilledInvoice,
            self::SettlementNoteCorrection => self::SettlementNote,
            self::CommissionInvoiceCorrection => self::CommissionInvoice,
            default => null,
        };
    }
}
