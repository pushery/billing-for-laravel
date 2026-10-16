<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Mollie;

use InvalidArgumentException;
use Mollie\Api\Http\Requests\ApplePayPaymentSessionRequest;
use Mollie\Api\Resources\AnyResource;
use Pushery\Billing\ValueObjects\PaymentAccount;

/**
 * Apple Pay on a shop's own domain, through Mollie.
 *
 * When Apple Pay JS asks the page to validate the merchant, the page hands the validation URL and its own domain to
 * session() and answers Apple Pay with what comes back, unchanged. The token Apple Pay hands the page once the
 * customer has authorized the payment goes into the charge's options as `applePayToken`. The domain serves Mollie's
 * validation file at `/.well-known/apple-developer-merchantid-domain-association`.
 */
final readonly class MollieApplePay
{
    public function __construct(
        private MollieClientFactory $clients = new MollieClientFactory,
    ) {}

    /**
     * The merchant session for Apple Pay JS, as Apple generated it. It is opaque, and the page hands it on unchanged.
     *
     * @param  string  $validationUrl  the URL of Apple's ApplePayValidateMerchant event
     * @param  string  $domain  the shop's domain as the browser's location bar shows it, such as `shop.example`
     * @return array<array-key, mixed>
     *
     * @throws InvalidArgumentException when the validation URL is no https address of Apple, or the domain is no host name
     */
    public function session(PaymentAccount $account, string $validationUrl, string $domain): array
    {
        $host = parse_url($validationUrl, PHP_URL_HOST);

        if (parse_url($validationUrl, PHP_URL_SCHEME) !== 'https' || ! is_string($host) || ($host !== 'apple.com' && ! str_ends_with($host, '.apple.com'))) {
            throw new InvalidArgumentException("The validation URL of an Apple Pay session is an https address of Apple, and `{$validationUrl}` is not.");
        }

        if (preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i', $domain) !== 1) {
            throw new InvalidArgumentException("The domain of an Apple Pay session is a host name such as `shop.example`, and `{$domain}` is not.");
        }

        $session = MollieValue::narrow(
            $this->clients->forAccount($account)->send(new ApplePayPaymentSessionRequest($domain, $validationUrl, $account->profileId)),
            AnyResource::class,
        );

        // Decoded once more because the SDK keeps a nested part of the answer as an object, and the page hands the
        // session on as JSON in any case.
        $decoded = json_decode((string) json_encode($session?->toArray() ?? []), true);

        return is_array($decoded) ? $decoded : [];
    }
}
