<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * WHAT was sold, frozen onto the routed charge beside how much of it the merchant gets.
 *
 * The three amounts on a `MerchantCharge` say what moved. They do not say what for — and a consumer that
 * credits the share to a balance instead of transferring it needs exactly that, because a ledger entry
 * carries a type and an append-only ledger with the wrong one cannot be corrected, only offset.
 *
 * ## Why it is stored rather than derived
 *
 * It IS derivable, and that is the trap: each lane derives differently, over a different number of hops, and
 * one of them cannot be derived at all.
 *
 * - a one-off purchase resolves through its payment reference to an addon key;
 * - a subscription cycle takes three hops — the charge reference is an invoice, which names an order, which
 *   names a subscription — and the middle hop only exists on installations whose orders this package writes;
 * - a tip persists nothing that names it. It is only knowable by the ABSENCE of the other two, and absence
 *   is the wrong answer during a race: a purchase whose addon row has not landed yet reads as a tip.
 *
 * The lane knows this for certain at the moment it opens the checkout. Every route back to it afterwards is
 * longer, and the last one is wrong.
 *
 * ## Why the cases name this package's lanes and not a consumer's revenue types
 *
 * These are the three ways money reaches a merchant through this package, which is the only thing it can
 * honestly assert. A consumer's own categories are usually finer — a marketplace may separate sponsorship
 * from paid content though both arrive here as a purchase — so this is the input to that mapping, never a
 * replacement for it.
 *
 * Null on a row means "written before this was recorded" rather than "none of these", exactly as the other
 * frozen columns beside it use null.
 */
enum MerchantChargePurpose: string
{
    /**
     * A recurring cycle: the charge reference is the invoice the provider raised for the period.
     *
     * Recorded by the webhook effect that sees the paid invoice, because that is where a cycle becomes a
     * payment — there is no checkout for the second month.
     */
    case Subscription = 'subscription';

    /**
     * A one-off purchase of something the catalog names.
     *
     * The addon key is not carried here on purpose. It is already on the purchase this charge belongs to,
     * and copying it would give the same fact two homes that can disagree; what the column adds is the
     * distinction between a purchase and a tip, which nothing else records.
     */
    case AddonPurchase = 'addon_purchase';

    /**
     * A tip: an amount a buyer chose, with no catalog entry behind it.
     *
     * This is the case that cannot be reconstructed afterwards, and therefore the one that justifies the
     * column. See the class docblock.
     */
    case Tip = 'tip';
}
