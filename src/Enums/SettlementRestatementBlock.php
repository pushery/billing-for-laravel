<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * Why a settlement could not be issued again after its creator's standing was corrected.
 *
 * Every one of these leaves the settlement as it was. A cancellation without a replacement would leave the
 * supply with no document at all, which is worse than the wrong one: the platform loses its deduction, and
 * the creator is left without the document they are owed. So the restatement happens as a pair or not at all.
 */
enum SettlementRestatementBlock: string
{
    /**
     * The settlement covers a month of transactions in one document.
     *
     * A standing that changes inside the month would split it into lines under two treatments, and a
     * collective document carries one. Until it can carry both, these are corrected by hand.
     */
    case CollectiveSettlement = 'collective_settlement';

    /** The standing now in force is unknown, and an unknown standing issues no document at all. */
    case StandingUnclarified = 'standing_unclarified';

    /**
     * The new standing states tax and the settlement does not record the rate of its supply.
     *
     * Settlements issued before the rate was recorded have only the rate they stated, and one that stated no
     * tax states no rate either. Guessing one would put a figure on a document that nothing supports.
     */
    case SupplyRateUnknown = 'supply_rate_unknown';

    /** The replacement would be a self-billed invoice and no agreement covers the date of the supply. */
    case NoSelfBillingAgreement = 'no_self_billing_agreement';

    /** The replacement would state tax that the creator's standing does not permit a document to show. */
    case TaxNotDisclosable = 'tax_not_disclosable';

    /** The replacement would be reverse-charged, and the settlement never recorded what was sold. */
    case ProductNotClassified = 'product_not_classified';

    /** The settlement the row points at no longer exists, or names no creator that can be found. */
    case OriginalUnresolvable = 'original_unresolvable';
}
