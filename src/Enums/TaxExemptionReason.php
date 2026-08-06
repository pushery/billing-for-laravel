<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * Why a sale carried no tax.
 *
 * A zero is not a fact on its own. The tax calculator returns the same `Money::zero` for a supply the buyer
 * accounts for, a supply placed outside the union, and a supply whose country has no rate — three legally
 * different outcomes that a document must state differently, and that a return must treat differently.
 * Without a reason traveling beside the amount, all three arrive at the issuer as an indistinguishable
 * nothing, and the issuer has to guess which sentence to print.
 *
 * That guess is the failure this enum removes. An exemption a document cannot name is one it cannot claim:
 * an export invoice without its exemption note is not a zero-rated invoice, it is an invoice that forgot to
 * charge tax — and the two look identical to everyone except an auditor.
 *
 * Deliberately NOT modeled here: a nil rate a country grants for a category of goods. That is a rate of
 * zero on a taxable supply, not an exemption, and folding the two together would let a supply that must
 * appear in a return quietly adopt the treatment of one that must not.
 */
enum TaxExemptionReason: string
{
    /**
     * The buyer accounts for the tax instead of the seller.
     *
     * The supply is taxable and it is taxed — just not by the party issuing the document. It still belongs
     * in a return on both sides, which is why this is not interchangeable with a supply placed outside.
     */
    case ReverseCharge = 'reverse_charge';

    /**
     * The supply is placed outside the union, so no member state's tax is due on it.
     *
     * The outbound case the shipped profile is built for: a seller inside the union supplying a buyer
     * outside it. Distinct from reverse charge in that no counterpart accounts for union tax at all.
     */
    case SuppliedOutsideTheUnion = 'supplied_outside_the_union';
}
