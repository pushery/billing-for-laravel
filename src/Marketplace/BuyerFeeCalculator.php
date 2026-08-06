<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use Pushery\Billing\Enums\BuyerFeeModel;
use Pushery\Billing\Exceptions\InvalidBillingConfig;
use Pushery\Billing\ValueObjects\FeeLine;
use Pushery\Billing\ValueObjects\Money;

/**
 * The fee a buyer pays on a C2C sale, resolved into its own tax-carrying line.
 *
 * This is an intermediation service the platform renders to the buyer — its FIRST supply in the
 * intermediary posture, the one the platform earns for itself rather than passes through. Everything about
 * it exists to keep it separate: a separate line, a separate net and tax, a separate revenue account. Netted
 * into the item price or the seller's turnover, a taxable supply of the platform's own would vanish.
 *
 * Two things it must get right that a naive reading gets wrong. Its place of supply is where the mediated
 * sale happens, NOT where the buyer banks — the location follows the transaction, not the person paying the
 * fee. And it is quoted gross: the buyer is told 5.00, and the net and tax are read back out of that, never
 * added on top of it.
 */
final readonly class BuyerFeeCalculator
{
    public function __construct(private Repository $config) {}

    /** Whether buyer fees are switched on at all. */
    public function isEnabled(): bool
    {
        return (bool) $this->config->get('billing.marketplace.buyer_fee.enabled', false);
    }

    /**
     * The buyer fee on a sale, or null when the feature is off.
     *
     * @param  Money  $saleGross  the price of the mediated item, which a percentage fee is taken of
     * @param  string  $placeOfSupply  where the mediated sale happens — the fee's place of supply, and the
     *                                 country whose rate it carries
     * @param  int  $taxBps  that country's rate for this supply, in basis points
     */
    public function feeFor(Money $saleGross, string $placeOfSupply, int $taxBps): ?FeeLine
    {
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

        // Gross to net and its tax as the difference, so the line sums back to what the buyer paid.
        // baseFromMarkup(0) returns [gross, 0], so a tax-free jurisdiction needs no branch of its own.
        [$net, $tax] = $gross->baseFromMarkup($taxBps);

        return new FeeLine($gross, $net, $tax, strtoupper($placeOfSupply));
    }

    /** The revenue account the fee is booked to — its separateness is the point, the number is config. */
    public function revenueAccount(): string
    {
        $value = $this->config->get('billing.marketplace.buyer_fee.revenue_account', '8510');

        return is_string($value) && $value !== '' ? $value : '8510';
    }

    /** The gross fee for a sale, from the configured model. */
    private function grossFeeFor(Money $saleGross): Money
    {
        // A buyer fee is charged ON TOP of the item price, so a fixed amount is simply that amount — it is
        // not capped by the sale, the way a seller-side deduction is capped by the payout it comes out of.
        return match ($this->model()) {
            BuyerFeeModel::Percent => $saleGross->proportion($this->bps(), 10_000),
            BuyerFeeModel::Fixed => new Money($this->fixedMinor(), $saleGross->currency),
        };
    }

    private function model(): BuyerFeeModel
    {
        $value = $this->config->get('billing.marketplace.buyer_fee.model', 'percent');

        // A non-string is refused outright rather than coerced to an empty string first: coercing would make
        // "not a string" and "an unrecognized string" the same rejection, and only the second is the one a
        // typo produces — the guard has to name the model, not a stand-in for it.
        if (! is_string($value) || BuyerFeeModel::tryFrom($value) === null) {
            throw InvalidBillingConfig::forKey('billing.marketplace.buyer_fee.model', "must be 'percent' or 'fixed'");
        }

        return BuyerFeeModel::from($value);
    }

    private function bps(): int
    {
        $value = $this->config->get('billing.marketplace.buyer_fee.bps', 0);

        if (! is_int($value) || $value < 0 || $value > 10_000) {
            throw InvalidBillingConfig::forKey('billing.marketplace.buyer_fee.bps', 'must be an integer between 0 and 10000');
        }

        return $value;
    }

    private function fixedMinor(): int
    {
        $value = $this->config->get('billing.marketplace.buyer_fee.fixed_minor', 0);

        if (! is_int($value) || $value < 0) {
            throw InvalidBillingConfig::forKey('billing.marketplace.buyer_fee.fixed_minor', 'must be a non-negative integer');
        }

        return $value;
    }
}
