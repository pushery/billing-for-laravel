<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * Which document a buyer's purchase produces — chosen from the sale, not from a setting.
 *
 * A consumer sale carries no invoicing duty, so the platform issues the LEAST document each purchase needs
 * and collects the least data. A small domestic purchase gets a simplified receipt; a larger one, or a
 * cross-border one, gets a plain payment record; and only a buyer who explicitly ASKS for a full invoice
 * has their name and address collected at all. The anonymity that follows is the point, not a side effect:
 * capturing a name for every purchase would be data collection with no legal ground, and it would identify
 * the buyer — and, in a commission chain, expose the merchant to them as the seller.
 *
 * The tiers are named for what they ARE, not for a statute. Which purchase falls into which, and at what
 * threshold, is a jurisdiction's answer and lives in its profile — a consumer elsewhere reads these three
 * words and no national rule.
 */
enum FanReceiptTier: string
{
    /** A short receipt: seller, date, the gross with its tax shown in one sum, and the rate. No buyer data. */
    case Simplified = 'simplified';

    /** A plain proof of payment, not an invoice. No buyer data. */
    case PaymentRecord = 'payment_record';

    /** A full invoice with every mandatory field — issued only when the buyer asks, collecting their data then. */
    case FullInvoice = 'full_invoice';

    /**
     * Whether a document of this tier splits the tax out, or states it in one sum with the gross.
     *
     * Only a full invoice itemises. The two shorter tiers deliberately name nobody, and a split net/tax block
     * on an anonymous document reads as an invoice with its recipient missing — which sends a reader looking
     * for something that was never supposed to be there.
     */
    public function itemisesTax(): bool
    {
        return $this === self::FullInvoice;
    }
}
