<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use InvalidArgumentException;
use Pushery\Billing\Enums\PaymentLineType;

/**
 * One line of the order a payment pays for, as a provider that takes order lines needs it.
 *
 * Amounts include VAT. The total is the unit price times the quantity, less the discount, and the totals of all
 * lines add up to the amount charged. Some methods refuse a payment without its lines, Klarna and in3 among them.
 */
final readonly class PaymentLine
{
    /**
     * @param  string  $description  what the customer bought, as the line reads
     * @param  int  $quantity  how many, at least one
     * @param  Money  $unitPrice  the price of one, VAT included
     * @param  Money  $totalAmount  the line's total, VAT and discount included
     * @param  ?string  $vatRate  the VAT rate in percent with two decimals, such as `19.00`
     * @param  ?Money  $vatAmount  the VAT the total includes
     * @param  ?Money  $discountAmount  the discount on this line, as a positive amount
     * @param  ?string  $sku  the article number, at most 64 characters
     *
     * @throws InvalidArgumentException for an empty description, a quantity below one, amounts in two currencies, a
     *                                  VAT rate in another format, a negative discount or a longer article number
     */
    public function __construct(
        public string $description,
        public int $quantity,
        public Money $unitPrice,
        public Money $totalAmount,
        public ?string $vatRate = null,
        public ?Money $vatAmount = null,
        public ?Money $discountAmount = null,
        public ?PaymentLineType $type = null,
        public ?string $sku = null,
        public ?string $imageUrl = null,
        public ?string $productUrl = null,
    ) {
        if (trim($description) === '') {
            throw new InvalidArgumentException('A payment line has a description.');
        }

        if ($quantity < 1) {
            throw new InvalidArgumentException("A payment line has a quantity of at least one, and `{$description}` has {$quantity}.");
        }

        $currencies = array_unique(array_map(
            static fn (Money $amount): string => $amount->currency,
            array_filter([$unitPrice, $totalAmount, $vatAmount, $discountAmount]),
        ));

        if (count($currencies) > 1) {
            throw new InvalidArgumentException("The amounts of the payment line `{$description}` are in one currency, and they are in ".implode(' and ', $currencies).'.');
        }

        if ($vatRate !== null && preg_match('/^\d{1,3}\.\d{2}$/', $vatRate) !== 1) {
            throw new InvalidArgumentException("The VAT rate of a payment line is a percentage with two decimals, such as `19.00`, and `{$vatRate}` is not.");
        }

        if ($discountAmount instanceof Money && $discountAmount->minorUnits < 0) {
            throw new InvalidArgumentException("The discount of the payment line `{$description}` is a positive amount.");
        }

        if ($sku !== null && mb_strlen($sku) > 64) {
            throw new InvalidArgumentException("The article number of a payment line has at most 64 characters, and `{$sku}` has more.");
        }
    }
}
