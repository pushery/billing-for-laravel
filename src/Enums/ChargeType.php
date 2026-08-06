<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * How a routed payment is constructed — and, with it, who carries the risk.
 *
 * This is not a technical preference between two provider calls that do the same thing. The two shapes put
 * the merchant-of-record in different places, and everything downstream follows: who bears a dispute, who
 * pays the provider's own processing fee, and who the buyer's receipt names. Choosing it by accident is how
 * a platform discovers, at the first chargeback, that it agreed to something it never read.
 *
 * It is decided per charge rather than once in config because a platform can legitimately run both — the
 * same operator may route a tip one way and a regulated sale the other.
 */
enum ChargeType: string
{
    /**
     * The provider moves the merchant's share as part of the payment itself.
     *
     * The payment is created on the PLATFORM's account and the merchant's share is moved with it, minus a
     * stated fee. This is the ordinary shape for a tipping or creator marketplace, and it is the default for
     * exactly that reason.
     *
     * ## Who carries the dispute and the provider's own fee: the PLATFORM, and `onBehalfOf` does not move it
     *
     * This docblock once said the connected account bears both. It does not, and the correction that
     * withdrew that claim then over-corrected in the other direction — it said `ChargeRouting::$onBehalfOf`
     * is "the axis the processing fee and dispute liability follow". That is also untrue.
     *
     * Checked against the provider's own documentation for Connect charge types on 2026-07-28:
     *
     * - **Destination charge**: the platform is the settlement merchant, the dispute is debited from the
     *   PLATFORM balance, and the fee is charged on the platform's own pricing. Not configurable here.
     * - **`on_behalf_of`**: makes the connected account the merchant of record for the payment — it is
     *   processed in that account's country, under that country's fee schedule, with that account's
     *   statement descriptor, address and phone on the buyer's statement. The country-specific fee is
     *   still **billed to the platform account**. It moves neither the fee nor the liability.
     * - Only a **direct charge** puts the dispute on the connected account's balance and lets the fee be
     *   billed to it.
     *
     * So the flag is not cosmetic and it is not the liability axis either: it changes which country's
     * rules and prices apply, and whose name the buyer sees. Both earlier wordings were wrong in opposite
     * directions, and the combination of them was the most expensive reading available — pick this type,
     * leave the flag alone, price the commission as if the creator absorbed chargebacks, and find out at
     * the first one.
     *
     * ## What is still NOT verified, and why it matters
     *
     * This is the provider's documentation, not a `balance_transaction.fee_details` from a real payment on
     * the pinned API version. Documentation describes intent; the payload is what actually happened, and
     * the two have differed before. A commission model that depends on the platform absorbing the
     * processing fee should rest on the payload, and obtaining one needs a live key against the pinned
     * version. Tracked internally; not named here, because this file ships and an issue id in a published
     * package points a reader at a tracker they cannot open.
     */
    case Destination = 'destination';

    /**
     * The platform takes the whole payment, then moves the merchant's share separately.
     *
     * The PLATFORM is the merchant of record and carries the dispute. That is the costly half, and it is
     * also the only shape available when the platform must be the one issuing the document — which is what
     * a deemed-supplier rule requires, regardless of how anybody would prefer the money to flow.
     */
    case SeparateTransfer = 'separate_transfer';

}
