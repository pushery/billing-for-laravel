<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use Pushery\Billing\Enums\BuyerFeeModel;
use Pushery\Billing\Enums\SupplyRegime;
use Pushery\Billing\Exceptions\DocumentRoleViolatesRegime;
use Pushery\Billing\Exceptions\InvalidBillingConfig;
use Pushery\Billing\ValueObjects\FeeLine;
use Pushery\Billing\ValueObjects\Money;

/**
 * The commission a SELLER pays for having their sale arranged — the platform's second intermediation supply.
 *
 * ## The half that was missing
 *
 * `SupplyRegime::chargesTheSellerAFee()` has answered true for intermediation since it was written, the
 * shipped configuration calls the buyer fee "a separate supply from the seller-side commission", and
 * `SellerReportingPeriod` states that under intermediation "the platform issues the seller a commission
 * invoice for its fee". None of that had a calculator or an issuer behind it: every commission number the
 * package drew belonged to a document owned by the BUYER.
 *
 * So the platform withheld a commission and documented it nowhere. It had made a taxable supply without
 * invoicing it, and the seller held no document to deduct the tax on the amount kept from them.
 *
 * ## Two things it does that the buyer-side calculator deliberately does not
 *
 * It REFUSES outside intermediation. A commission chain has the platform buying and reselling, so there is
 * no arranging fee to charge — a document claiming one there is the named red line the role guard already
 * enforces, and refusing at the calculator means the mistake is caught before a number is drawn.
 *
 * And a fixed fee is CAPPED by the sale. The buyer's fee is charged on top of the item price and is not
 * bounded by it; the seller's comes out of what reaches them, so a fee larger than the sale would pay the
 * seller a negative amount. That difference is stated in the buyer calculator's own comment and is the one
 * place a copy of it would be wrong.
 *
 * Like its sibling: quoted gross, with net and tax read back out of it, and its place of supply is where the
 * MEDIATED SALE happens rather than where the seller is established.
 */
final readonly class SellerFeeCalculator
{
    public function __construct(private Repository $config) {}

    /** Whether seller commissions are switched on at all. Off by default: no existing install changes. */
    public function isEnabled(): bool
    {
        return (bool) $this->config->get('billing.marketplace.seller_fee.enabled', false);
    }

    /**
     * The seller's commission on a mediated sale, or null when the feature is off or it comes to nothing.
     *
     * @param  Money  $saleGross  the price of the mediated item, which the commission is taken out of
     * @param  string  $placeOfSupply  where the mediated sale happens — the fee's place of supply
     * @param  int  $taxBps  that country's rate for this supply, in basis points
     * @param  SupplyRegime  $regime  refused outside intermediation, before any number is drawn
     */
    public function feeFor(Money $saleGross, string $placeOfSupply, int $taxBps, SupplyRegime $regime): ?FeeLine
    {
        if ($regime !== SupplyRegime::Intermediation) {
            throw DocumentRoleViolatesRegime::commissionInvoiceInCommissionChain();
        }

        if (! $this->isEnabled()) {
            return null;
        }

        if ($taxBps < 0) {
            throw new InvalidArgumentException("A tax rate cannot be negative; got {$taxBps}.");
        }

        $gross = $this->grossFeeFor($saleGross);

        if ($gross->isZero()) {
            return null;
        }

        // Gross to net and its tax as the difference, so the line sums back to what was withheld.
        [$net, $tax] = $gross->baseFromMarkup($taxBps);

        return new FeeLine($gross, $net, $tax, strtoupper($placeOfSupply));
    }

    /** The revenue account the commission is booked to — separate from the buyer fee, because it is one. */
    public function revenueAccount(): string
    {
        $value = $this->config->get('billing.marketplace.seller_fee.revenue_account', '8511');

        return is_string($value) && $value !== '' ? $value : '8511';
    }

    /** The gross commission for a sale, from the configured model, never more than the sale itself. */
    private function grossFeeFor(Money $saleGross): Money
    {
        $fee = match ($this->model()) {
            BuyerFeeModel::Percent => $saleGross->proportion($this->bps(), 10_000),
            BuyerFeeModel::Fixed => new Money($this->fixedMinor(), $saleGross->currency),
        };

        // Capped, and this is the line that makes it a seller fee rather than a copy of the buyer one. A
        // commission comes OUT of the payout: a fixed 5.00 on a 3.00 sale would otherwise owe the seller
        // minus 2.00, which is not a payout at all but a debt the sale created.
        return $fee->minorUnits > $saleGross->minorUnits ? $saleGross : $fee;
    }

    private function model(): BuyerFeeModel
    {
        $value = $this->config->get('billing.marketplace.seller_fee.model', 'percent');

        // Refused rather than coerced, for the reason the buyer-side calculator gives: coercing a non-string
        // to an empty one makes "wrong type" and "typo" the same rejection, and only the second is what a
        // person actually produces.
        if (! is_string($value) || BuyerFeeModel::tryFrom($value) === null) {
            throw InvalidBillingConfig::forKey('billing.marketplace.seller_fee.model', "must be 'percent' or 'fixed'");
        }

        return BuyerFeeModel::from($value);
    }

    private function bps(): int
    {
        $value = $this->config->get('billing.marketplace.seller_fee.bps', 0);

        if (! is_int($value) || $value < 0 || $value > 10_000) {
            throw InvalidBillingConfig::forKey('billing.marketplace.seller_fee.bps', 'must be an integer between 0 and 10000');
        }

        return $value;
    }

    private function fixedMinor(): int
    {
        $value = $this->config->get('billing.marketplace.seller_fee.fixed_minor', 0);

        if (! is_int($value) || $value < 0) {
            throw InvalidBillingConfig::forKey('billing.marketplace.seller_fee.fixed_minor', 'must be a non-negative integer');
        }

        return $value;
    }
}
