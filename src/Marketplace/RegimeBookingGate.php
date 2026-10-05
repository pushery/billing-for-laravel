<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Pushery\Billing\Enums\DatevTransaction;
use Pushery\Billing\Enums\SupplyRegime;
use Pushery\Billing\Exceptions\RegimeNotPermitted;

/**
 * Keeps the goods leg of an intermediated sale out of the platform's own revenue.
 *
 * ## What goes wrong without it
 *
 * In an intermediated sale the platform never owns the goods: money passes through it from one user to
 * another. Booked to a revenue account it becomes the platform's turnover — and the usual revenue accounts
 * are the kind that apply a tax rate by themselves, so the booking does not merely misclassify the money, it
 * invents tax on it. Nobody charged that tax and nobody collected it; it appears in the books because of
 * where the amount was written down.
 *
 * The amounts involved are not small relative to the platform's actual income: the platform earns a fee of a
 * few percent and would be booking the whole sale.
 *
 * ## Why a gate rather than care at each call site
 *
 * There are three places that turn a transaction into a booking, and all three take the amount from the same
 * settlement. Care at each is the arrangement where two are right and the third is the one nobody reads
 * again. So the regime decides the account here, once, and asking for revenue on a leg that has none is
 * refused rather than quietly granted.
 */
final readonly class RegimeBookingGate
{
    /** Which account kind the goods leg of a sale belongs to under this regime. */
    public function goodsTransaction(SupplyRegime $regime): DatevTransaction
    {
        return match ($regime) {
            // The platform bought and sold: the sale is its own turnover.
            SupplyRegime::CommissionChain => DatevTransaction::FanRevenueStandard,
            // The platform only arranged it. The money is passing through and belongs to somebody else until
            // it is paid out.
            SupplyRegime::Intermediation => DatevTransaction::TransitItems,
        };
    }

    /** Whether a booking of the goods leg is permitted under this regime. */
    public function permits(SupplyRegime $regime, DatevTransaction $transaction): bool
    {
        return $regime !== SupplyRegime::Intermediation || $this->holdsMoneyPassingThrough($transaction);
    }

    /**
     * Refuse a goods leg about to be booked as the platform's own income.
     *
     * @throws RegimeNotPermitted
     */
    public function assertPermitted(SupplyRegime $regime, DatevTransaction $transaction): void
    {
        if (! $this->permits($regime, $transaction)) {
            throw RegimeNotPermitted::goodsAsRevenueUnderIntermediation($transaction->value);
        }
    }

    /**
     * Whether an account kind holds money passing through the platform: the only kinds the goods leg of an
     * arranged sale may be booked to.
     *
     * Listed as what may take the goods rather than as what may not. An account kind added later is then refused
     * for the goods leg until it is named here, which is the direction that fails safe: every revenue account,
     * the cross-border ones and the exchange gains included, is refused without having to be listed.
     */
    private function holdsMoneyPassingThrough(DatevTransaction $transaction): bool
    {
        return in_array($transaction, [
            DatevTransaction::TransitItems,
            DatevTransaction::MoneyTransit,
            DatevTransaction::CreatorLiabilities,
        ], true);
    }
}
