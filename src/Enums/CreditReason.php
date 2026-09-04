<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * Why an owner's credit balance moved.
 *
 * The balance is a running total and says only WHAT somebody has. Every movement carries one of these, so
 * the balance can always be taken apart into the events that produced it — which is what a support agent
 * needs when a customer disputes a figure, and what an auditor needs when the balance is the only trace a
 * refund or a proration left behind.
 *
 * EVERY CASE HERE HAS A PRODUCER. That is deliberate and worth keeping: a reason nobody writes reads as a
 * movement class the system supports, so a caller picks it, and the value quietly means "somebody once
 * planned this" rather than "this happened". Add a case together with the code that writes it, never ahead
 * of it — the local billing engine will bring its own when it offsets an order against a balance.
 */
enum CreditReason: string
{
    /**
     * Unused time on a plan the owner swapped away from, credited back.
     *
     * Written by CreditBalanceProrationStrategy. It is the movement most likely to be questioned, because
     * it appears without the customer doing anything they would recognize as a purchase.
     */
    case ProrationCredit = 'proration_credit';

    /** A paid add-on that grants spendable balance rather than metered units. Written by CreditAddonPurchase. */
    case AddonTopup = 'addon_topup';

    /**
     * A balance-granting add-on taken back — a refund, a chargeback, or an admin clawback.
     *
     * Written by AddonRefunds as a debit, so the entry is negative. It is a separate case from the top-up
     * rather than a negative top-up because the two answer different questions: one is what the customer
     * bought, the other is what was undone, and a report that could not tell them apart would show a
     * refunded add-on as never having been sold.
     */
    case AddonReversal = 'addon_reversal';

    /**
     * Credit spent against a due billing cycle, so the customer is charged only the remainder.
     *
     * Written by the local billing engine. It is the one movement that makes the balance go DOWN for a
     * reason the customer asked for — the other debit, a reversal, happens to them. Keeping the two apart
     * is what lets a support agent tell "they used their credit" from "we took it back".
     */
    case ChargeOffset = 'charge_offset';

    /**
     * A charge offset given back because the cycle it was spent on was never collected.
     *
     * Written by the local billing engine when the provider refuses the remainder. The credit is spent
     * BEFORE the charge on purpose — deciding a figure and debiting it after a provider round trip lets two
     * cycles for the same owner spend the same balance twice — so a refusal has to return it, and this is
     * the entry that says so.
     *
     * A separate case rather than a positive ChargeOffset, for the reason AddonReversal is separate from
     * AddonTopup: "they used their credit" and "the attempt failed, here it is back" are different answers,
     * and a report that netted them would show a dunning customer as having spent nothing.
     */
    case ChargeOffsetReturned = 'charge_offset_returned';
}
