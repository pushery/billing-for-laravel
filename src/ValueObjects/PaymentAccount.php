<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use InvalidArgumentException;
use LogicException;
use SensitiveParameter;

/**
 * One account at one payment provider, supplied by the application at run time instead of read from the
 * install's configuration.
 *
 * An application that takes payments for many shops, each with its own Mollie or Stripe account, builds one
 * of these per shop and provider and hands it to `BillingManager::forAccount()`. Where the credential comes
 * from and how it is stored stay with the application; the package holds the account for the length of a
 * call.
 *
 * The two secrets never show: `var_dump`, `print_r` and a debugger see them masked, and the object
 * refuses to be serialized, so it cannot travel into a queue payload, a cache or a log by accident. A job
 * carries the provider and the key, and finds the account again through `PaymentAccounts::find()`.
 */
final readonly class PaymentAccount
{
    /** A driver name as the service providers register it. */
    private const string PROVIDER_PATTERN = '/^[a-z][a-z0-9_-]{0,31}$/';

    /** A key is part of the webhook address: lowercase letters, digits and inner hyphens, at most 64 characters. */
    private const string KEY_PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/';

    /** A host name: labels of letters, digits and inner hyphens, joined by dots, without a scheme, a port or a path. */
    private const string HOST_PATTERN = '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$/i';

    /**
     * @param  string  $provider  the driver the account belongs to, such as `mollie` or `stripe`
     * @param  string  $key  the application's stable name for the account, which becomes part of its webhook address
     * @param  string  $credential  the API key, or for Mollie an OAuth access token
     * @param  bool  $testmode  whether calls go to the provider's test mode; a Mollie API key decides by its prefix and has to agree
     * @param  string|null  $profileId  the Mollie profile payments are created on, which an OAuth token requires
     * @param  string|null  $webhookSecret  the secret the provider signs this account's webhooks with
     * @param  list<string>  $redirectHosts  the hosts the provider serves this account's payment pages from beside
     *                                       its own, such as the custom domain of a Stripe account's Checkout
     *
     * @throws InvalidArgumentException when the provider or the key has the wrong shape, the credential is empty, or
     *                                  a redirect host is no host name
     */
    public function __construct(
        public string $provider,
        public string $key,
        #[SensitiveParameter]
        public string $credential,
        public bool $testmode = false,
        public ?string $profileId = null,
        #[SensitiveParameter]
        public ?string $webhookSecret = null,
        public array $redirectHosts = [],
    ) {
        if (preg_match(self::PROVIDER_PATTERN, $provider) !== 1) {
            throw new InvalidArgumentException("A payment account names its provider the way the driver is registered, such as `mollie` or `stripe`, not `{$provider}`.");
        }

        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException("The key of a payment account becomes part of its webhook address: lowercase letters, digits and inner hyphens, at most 64 characters, not `{$key}`.");
        }

        if (trim($credential) === '') {
            throw new InvalidArgumentException("The payment account `{$provider}/{$key}` has no credential.");
        }

        foreach ($redirectHosts as $host) {
            if (preg_match(self::HOST_PATTERN, $host) !== 1) {
                throw new InvalidArgumentException("A redirect host of the payment account `{$provider}/{$key}` is a host name, such as `payments.example.com`, without a scheme or a path.");
            }
        }
    }

    /**
     * What a debugger, `var_dump` and `print_r` show: everything but the two secrets.
     *
     * @return array{provider: string, key: string, credential: string, testmode: bool, profileId: string|null, webhookSecret: string|null, redirectHosts: list<string>}
     */
    public function __debugInfo(): array
    {
        return [
            'provider' => $this->provider,
            'key' => $this->key,
            'credential' => '(hidden)',
            'testmode' => $this->testmode,
            'profileId' => $this->profileId,
            'webhookSecret' => $this->webhookSecret === null ? null : '(hidden)',
            'redirectHosts' => $this->redirectHosts,
        ];
    }

    /**
     * Refused: a serialized account would carry its credential into a queue, a cache or a session.
     *
     * @return array<string, mixed>
     *
     * @throws LogicException always
     */
    public function __serialize(): array
    {
        throw new LogicException('A PaymentAccount carries a credential and is never serialized. Pass its provider and key, and find it again with PaymentAccounts::find().');
    }

    /**
     * Refused as well, so a payload written by hand cannot build an account past the checks of the constructor.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws LogicException always
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('A PaymentAccount is never unserialized. Find it again with PaymentAccounts::find().');
    }
}
