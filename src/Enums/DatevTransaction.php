<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * The business transactions a DATEV booking can be, each of which resolves to a specific account.
 *
 * This enum is the STRUCTURE the package owns; the account NUMBERS behind each case are values that live in
 * config (a chart of accounts is jurisdiction-specific, and a consumer with a different accountant's frame
 * changes numbers, not code). Keeping the set of transactions here — rather than a single revenue account —
 * is what lets each booking land on the right account, which under DATEV carries its own tax logic (an
 * "Automatikkonto"), instead of one revenue account for every rate, country and document role.
 *
 * The costliest transaction to get wrong is the PSP fee: it is a service supplied by an Irish company
 * (§13b Abs. 1 UStG), booked like an EU input — NOT a bank charge on a money-transfer account, which is the
 * classic audit finding.
 */
enum DatevTransaction: string
{
    /** Fan revenue at the standard rate (19% DE). */
    case FanRevenueStandard = 'fan_revenue_standard';

    /** Fan revenue at the reduced rate for e-books/e-papers (7% DE). */
    case FanRevenueReduced = 'fan_revenue_reduced';

    /** OSS revenue, resolved per destination country (a list, not one account). */
    case OssRevenue = 'oss_revenue';

    /** Commission / fee revenue (regime V), a buyer fee posted as a sub-account. */
    case CommissionRevenue = 'commission_revenue';

    /** Creator payout to a German standard-rated business — input VAT 19%. */
    case CreatorInputDeStandard = 'creator_input_de_standard';

    /** Creator payout to a small business, private person or EU small business — no tax. */
    case CreatorInputExempt = 'creator_input_exempt';

    /** Creator payout to an EU business — §13b Abs. 1, the account books the reverse-charge VAT itself. */
    case CreatorInputEuReverseCharge = 'creator_input_eu_reverse_charge';

    /** Creator payout to a third-country business — §13b Abs. 2 Nr. 1. */
    case CreatorInputThirdCountryReverseCharge = 'creator_input_third_country_reverse_charge';

    /** The rare reduced-rate reverse-charge inputs (7%). */
    case CreatorInputEuReverseChargeReduced = 'creator_input_eu_reverse_charge_reduced';
    case CreatorInputThirdCountryReverseChargeReduced = 'creator_input_third_country_reverse_charge_reduced';

    /** The payment-service-provider fee — §13b input, NOT a money-transfer/bank-charge account. */
    case PspFee = 'psp_fee';

    /** Money in transit between the provider and the bank. */
    case MoneyTransit = 'money_transit';

    /** Amounts owed to creators (a collective liability account). */
    case CreatorLiabilities = 'creator_liabilities';

    /** Voucher liabilities — no VAT on issue (§3 Abs. 15 UStG). */
    case VoucherLiabilities = 'voucher_liabilities';

    /** Pass-through items (regime V only) — no VAT (§10 Abs. 1 S. 5 UStG). */
    case TransitItems = 'transit_items';

    /** Rounding differences — cent amounts, VAT-neutral. */
    case RoundingDifference = 'rounding_difference';

    /** Other operating income, e.g. an expired voucher — not taxable. */
    case OtherIncome = 'other_income';
}
