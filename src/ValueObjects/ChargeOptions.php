<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use DateTimeInterface;
use InvalidArgumentException;

/**
 * What a one-off payment for an order tells the provider beyond its amount: where the customer comes back to, where
 * the provider reports the status, the language of its page, the methods to offer, values to keep with the payment,
 * and the order's lines and addresses.
 *
 * Every field is optional, and a driver passes on what its provider understands; each driver's documentation says
 * which fields that is. Without options a charge is the one it always was.
 */
final readonly class ChargeOptions
{
    /** The metadata key the rails write themselves, which a caller's metadata may not take over. */
    public const string RESERVED_METADATA_KEY = 'billing_reference';

    /**
     * @param  ?string  $returnUrl  where the customer comes back to after paying, such as the shop's page of the order
     * @param  ?string  $cancelUrl  where the customer goes who cancels on the provider's page
     * @param  ?string  $webhookUrl  where the provider reports the payment's status, in place of the address the driver sets
     * @param  ?string  $locale  the language of the provider's payment page, such as `de_DE`
     * @param  list<string>  $methods  the payment methods to offer, empty for every method the account has turned on
     * @param  array<string, string|int>  $metadata  values to keep with the payment, such as the shop and the order number
     * @param  list<PaymentLine>  $lines  the order's lines, which some methods require
     * @param  ?string  $cardToken  the token the provider's card fields created for the card the customer entered
     * @param  ?string  $applePayToken  the token Apple Pay handed the page after the customer authorized the payment, as JSON
     * @param  ?string  $captureMode  `manual` to authorize the payment and capture it later, `automatic` for the provider's default
     * @param  ?DateTimeInterface  $expiresAt  when the provider's payment page stops taking the payment, where a charge may set
     *                                         it: a Checkout Session on Stripe expires then, from 30 minutes to 24 hours after
     *                                         it is created. Mollie keeps the lifetime of each method.
     *
     * @throws InvalidArgumentException for an address that is not absolute http or https, a locale in another format,
     *                                  an empty method, metadata under the key the rails write or a value that is not
     *                                  text or a whole number, a line that is not a PaymentLine, an empty card token or
     *                                  an Apple Pay token that is no JSON, or a capture mode other than `automatic` or `manual`
     */
    public function __construct(
        public ?string $returnUrl = null,
        public ?string $cancelUrl = null,
        public ?string $webhookUrl = null,
        public ?string $locale = null,
        public array $methods = [],
        public array $metadata = [],
        public array $lines = [],
        public ?PaymentAddress $billingAddress = null,
        public ?PaymentAddress $shippingAddress = null,
        public ?string $cardToken = null,
        public ?string $applePayToken = null,
        public ?string $captureMode = null,
        public ?DateTimeInterface $expiresAt = null,
    ) {
        foreach (['return' => $returnUrl, 'cancel' => $cancelUrl, 'webhook' => $webhookUrl] as $kind => $url) {
            if ($url !== null && ! $this->isAbsolute($url)) {
                throw new InvalidArgumentException("The {$kind} address of a charge is an absolute http or https address, and `{$url}` is not.");
            }
        }

        if ($locale !== null && preg_match('/^[a-z]{2}_[A-Z]{2}$/', $locale) !== 1) {
            throw new InvalidArgumentException("The locale of a charge is a language and a country, such as `de_DE`, and `{$locale}` is not.");
        }

        if ($this->anyRefused($methods, static fn (mixed $method): bool => ! is_string($method) || trim($method) === '')) {
            throw new InvalidArgumentException('The methods of a charge are the ids of payment methods, and one of them is empty.');
        }

        if (array_key_exists(self::RESERVED_METADATA_KEY, $metadata)) {
            throw new InvalidArgumentException('The rails keep `'.self::RESERVED_METADATA_KEY.'` with a payment themselves, so the metadata of a charge cannot carry it.');
        }

        if ($this->anyRefused($metadata, static fn (mixed $value): bool => ! is_string($value) && ! is_int($value))) {
            throw new InvalidArgumentException('The metadata of a charge holds text and whole numbers, nothing else.');
        }

        if ($this->anyRefused($lines, static fn (mixed $line): bool => ! $line instanceof PaymentLine)) {
            throw new InvalidArgumentException('The lines of a charge are PaymentLine values.');
        }

        if ($cardToken !== null && trim($cardToken) === '') {
            throw new InvalidArgumentException('The card token of a charge is not empty.');
        }

        if ($applePayToken !== null && ! is_array(json_decode($applePayToken, true))) {
            throw new InvalidArgumentException('The Apple Pay token of a charge is the token Apple Pay handed the page, encoded as JSON.');
        }

        if ($captureMode !== null && ! in_array($captureMode, ['automatic', 'manual'], true)) {
            throw new InvalidArgumentException("The capture mode of a charge is `automatic` or `manual`, and `{$captureMode}` is neither.");
        }
    }

    /** Whether an address is absolute, with http or https and a host. */
    private function isAbsolute(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && ($parts['host'] ?? '') !== '';
    }

    /**
     * Whether a value of a list fails the test, the list read as what a caller can hand over at run time rather
     * than as the type it declares: code outside static analysis is refused here, with a reason, not further in.
     *
     * @param  array<array-key, mixed>  $values
     * @param  callable(mixed): bool  $refused
     */
    private function anyRefused(array $values, callable $refused): bool
    {
        return array_any($values, $refused);
    }
}
