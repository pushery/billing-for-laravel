<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Mollie;

use InvalidArgumentException;
use Mollie\Api\Http\Data\Address;
use Mollie\Api\Http\Data\DataCollection;
use Mollie\Api\Http\Data\OrderLine;
use Pushery\Billing\ValueObjects\ChargeOptions;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\PaymentAddress;
use Pushery\Billing\ValueObjects\PaymentLine;

/**
 * The options of a charge in the shapes Mollie's payment request takes.
 *
 * Mollie takes every field of ChargeOptions: the return, cancel and webhook addresses, the locale, the methods, the
 * metadata, the lines, both addresses and the card token from Mollie Components. Both supported majors of the SDK
 * name these fields alike.
 */
final class MollieChargeOptions
{
    /**
     * The method the payment is created with: the methods the options name, else the token, else `creditcard` for a
     * payment Apple Pay authorized on the shop's page, else none, which lets the customer choose among every method
     * the account has turned on.
     *
     * @return list<string>|string|null
     *
     * @throws InvalidArgumentException when the token and the options both name methods
     */
    public static function method(string $token, ?ChargeOptions $options): array|string|null
    {
        $methods = $options instanceof ChargeOptions ? $options->methods : [];

        if ($methods === []) {
            $applePay = $options instanceof ChargeOptions && $options->applePayToken !== null;

            return $token !== '' ? $token : ($applePay ? 'creditcard' : null);
        }

        if ($token !== '') {
            throw new InvalidArgumentException("A charge names its method once, and this one names `{$token}` as its token and ".implode(', ', $methods).' in its options.');
        }

        return $methods;
    }

    /**
     * The order's lines in Mollie's shape, or null where the charge has none.
     *
     * @param  list<PaymentLine>  $lines
     * @return DataCollection<OrderLine>|null
     *
     * @throws InvalidArgumentException when the lines do not add up to the amount charged
     */
    public static function lines(array $lines, Money $amount): ?DataCollection
    {
        if ($lines === []) {
            return null;
        }

        $total = array_reduce($lines, static fn (Money $sum, PaymentLine $line): Money => $sum->plus($line->totalAmount), Money::zero($amount->currency));

        if (! $total->equals($amount)) {
            throw new InvalidArgumentException("The lines of a charge add up to {$total->format()}, and the charge is {$amount->format()}. Mollie refuses a payment whose lines and amount disagree.");
        }

        return new DataCollection(array_map(static fn (PaymentLine $line): OrderLine => new OrderLine(
            description: $line->description,
            quantity: $line->quantity,
            unitPrice: MollieAmount::toMollie($line->unitPrice),
            totalAmount: MollieAmount::toMollie($line->totalAmount),
            type: $line->type?->value,
            discountAmount: $line->discountAmount instanceof Money ? MollieAmount::toMollie($line->discountAmount) : null,
            vatRate: $line->vatRate,
            vatAmount: $line->vatAmount instanceof Money ? MollieAmount::toMollie($line->vatAmount) : null,
            sku: $line->sku,
            imageUrl: $line->imageUrl,
            productUrl: $line->productUrl,
        ), $lines));
    }

    /** An address in Mollie's shape, or null where the charge names none. */
    public static function address(?PaymentAddress $address): ?Address
    {
        if (! $address instanceof PaymentAddress) {
            return null;
        }

        return new Address(
            title: $address->title,
            givenName: $address->givenName,
            familyName: $address->familyName,
            organizationName: $address->organizationName,
            streetAndNumber: $address->streetAndNumber,
            streetAdditional: $address->streetAdditional,
            postalCode: $address->postalCode,
            email: $address->email,
            phone: $address->phone,
            city: $address->city,
            region: $address->region,
            country: $address->country,
        );
    }

    /**
     * The metadata of the payment: the caller's values and the reference the rails keep, which no option can hold.
     *
     * @param  array<string, string>|null  $trace
     * @return array<string, string|int>|null
     */
    public static function metadata(?ChargeOptions $options, ?array $trace): ?array
    {
        $metadata = [...($options instanceof ChargeOptions ? $options->metadata : []), ...($trace ?? [])];

        return $metadata === [] ? null : $metadata;
    }

    /**
     * The card token from Mollie Components and the Apple Pay token, which the request carries beside its named fields.
     *
     * @return array<string, string>
     */
    public static function additional(?ChargeOptions $options): array
    {
        if (! $options instanceof ChargeOptions) {
            return [];
        }

        return array_filter(['cardToken' => $options->cardToken, 'applePayPaymentToken' => $options->applePayToken], is_string(...));
    }
}
