<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * The business transactions a DATEV booking can be, each of which resolves to a specific account.
 *
 * This enum is the structure the package owns; the account numbers behind each case are values that live in
 * config (a chart of accounts is jurisdiction-specific, and a consumer with a different accountant's frame
 * changes numbers, not code). Keeping the set of transactions here — rather than a single revenue account —
 * is what lets each booking land on the right account, which under DATEV carries its own tax logic (an
 * "Automatikkonto"), instead of one revenue account for every rate, country and document role.
 *
 * The costliest transaction to get wrong is the PSP fee: it is a service supplied by an Irish company
 * (§13b Abs. 1 UStG), booked like an EU input — not a bank charge on a money-transfer account, which is the
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

    /**
     * Revenue from a service to a business in another member state, whose tax the recipient owes.
     *
     * Tax-free here, and never on the standard revenue account: that one is an Automatikkonto, and it would
     * derive domestic tax from a posting that carries none. The booking names the recipient's VAT id in the
     * EU field, which is what the recapitulative statement is read from.
     */
    case FanRevenueEuReverseCharge = 'fan_revenue_eu_reverse_charge';

    /** Revenue from a service to a business outside the union, which is not taxable here at all. */
    case FanRevenueThirdCountry = 'fan_revenue_third_country';

    /**
     * The platform's own sale of goods at the standard rate, in a sale it is treated as making itself.
     *
     * Taxed at home: below the distance-sale threshold the supply stays where the platform is, and a supply within
     * its own member state is a domestic one. A case of its own beside the fan revenue, so an accountant who keeps
     * goods and services apart can give it its own account. The shipped charts book both to the same one.
     */
    case GoodsRevenueStandard = 'goods_revenue_standard';

    /** The same sale of goods at the reduced rate. */
    case GoodsRevenueReduced = 'goods_revenue_reduced';

    /**
     * The platform's sale of goods declared in the Union scheme, resolved per destination country.
     *
     * A distance sale taxed in the member state where the transport ends. The charts keep one account per country
     * and rate in a block of their own for supplies taxable in another member state, so like the services this
     * resolves one account per destination and ships none.
     */
    case OssGoodsRevenue = 'oss_goods_revenue';

    /**
     * The platform's sale of imported goods declared in the import scheme, resolved per destination country.
     *
     * Ships unmapped in both charts: no account for it could be confirmed, so it is refused by name until the
     * operator configures one agreed with their accountant.
     */
    case ImportSchemeGoodsRevenue = 'import_scheme_goods_revenue';

    /** Commission / fee revenue (regime V), a buyer fee posted as a sub-account. */
    case CommissionRevenue = 'commission_revenue';

    /** Creator payout to a German standard-rated business — input VAT 19%. */
    case CreatorInputDeStandard = 'creator_input_de_standard';

    /**
     * Creator payout to a German REDUCED-rated business — input VAT 7% (books, e-books, cultural supplies).
     *
     * Deliberately unmapped in the shipped charts. The package has no account it can confirm for this, and
     * an unconfirmed account here is not a cosmetic error: the input VAT lands on the wrong line of the
     * advance return and the amount no longer agrees with the account it sits on. So this case exists in
     * order to be refused by name — an operator who has agreed an account with their accountant configures
     * `creator_input_de_reduced` for their chart and it books.
     *
     * Before this case existed the branch fell through to CreatorInputDeStandard, so a 7% input booked to
     * the 19% account with nothing raising anything.
     */
    case CreatorInputDeReduced = 'creator_input_de_reduced';

    /** Creator payout to a small business, private person or EU small business — no tax. */
    case CreatorInputExempt = 'creator_input_exempt';

    /**
     * The seller's supply of goods to the platform that is treated as supplying them itself (§ 4 Nr. 4c UStG).
     *
     * Exempt like the payout to a small business, but under a provision of its own and with the seller's right to
     * deduct behind it, so it does not share that account. Ships unmapped in both charts for the reason the
     * reduced-rate domestic input does: no account could be confirmed, and it is refused by name until one is.
     */
    case DeemedSupplyInputExempt = 'deemed_supply_input_exempt';

    /** Creator payout to an EU business — §13b Abs. 1, the account books the reverse-charge VAT itself. */
    case CreatorInputEuReverseCharge = 'creator_input_eu_reverse_charge';

    /** Creator payout to a third-country business — §13b Abs. 2 Nr. 1. */
    case CreatorInputThirdCountryReverseCharge = 'creator_input_third_country_reverse_charge';

    /** The rare reduced-rate reverse-charge inputs (7%). */
    case CreatorInputEuReverseChargeReduced = 'creator_input_eu_reverse_charge_reduced';
    case CreatorInputThirdCountryReverseChargeReduced = 'creator_input_third_country_reverse_charge_reduced';

    /** The payment-service-provider fee — §13b input, not a money-transfer/bank-charge account. */
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

    /**
     * A realized exchange gain — the euro that appears between collecting a sale and paying it out.
     *
     * It has nothing to do with VAT and is not a correction of the sale. The document's rate is frozen at
     * the tax point and stays frozen; the payout happens later at whatever rate the bank gave, and the
     * difference is income of its own. Booking it against the revenue account instead would move a figure
     * a tax return has already stated.
     *
     * Not an Automatikkonto: the account derives no VAT from the posting, because there is none to derive.
     */
    case ExchangeGain = 'exchange_gain';

    /** The same difference in the other direction — an expense, on its own account for the same reason. */
    case ExchangeLoss = 'exchange_loss';

    /** Whether a chart keeps this transaction as one account per destination country rather than as one account. */
    public function perCountry(): bool
    {
        return in_array($this, [self::OssRevenue, self::OssGoodsRevenue, self::ImportSchemeGoodsRevenue], true);
    }
}
