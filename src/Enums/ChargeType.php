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
     * - **Destination charge**: the platform is the settlement merchant, and the fee is charged on the
     *   platform's own pricing. Whether the dispute is debited from the platform's balance is NOT decided
     *   here — see the measurement below; it follows the connected account's type.
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
     * ## Verified against the API, and the answer is NOT a property of this enum
     *
     * Measured on 2026-08-06 against the live API on the pinned version `2025-08-27.basil`. The evidence is
     * not a `balance_transaction` — it is better than one. Both facts are STATED on the connected account
     * itself, in `account.controller`, so they can be read before a payment rather than inferred from one:
     *
     * | account type | `controller.fees.payer` | `controller.losses.payments` |
     * |---|---|---|
     * | `express`  | `application_express` — the PLATFORM pays | `application` — the PLATFORM absorbs |
     * | `standard` | `account` — the merchant pays            | `stripe` — not the platform's balance |
     *
     * Identical for DE and US accounts, so it is not a country effect.
     *
     * **So the incidence follows the ACCOUNT TYPE, not the charge type** — which is what the two earlier
     * wordings, and the sentence above them, all got wrong in their own way. `billing.marketplace.
     * onboarding.account_type` supports both values, and it is that setting, not this enum, that decides
     * who absorbs a chargeback and who pays the provider. Under the shipped default (`express`) the answer
     * for both is the platform.
     *
     * What a `balance_transaction.fee_details` would add is corroboration of the amount, not of the payer:
     * obtaining one needs a connected account that has completed hosted onboarding, and the payer is
     * already stated. `on_behalf_of` moves neither — see `ChargeRouting`.
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
