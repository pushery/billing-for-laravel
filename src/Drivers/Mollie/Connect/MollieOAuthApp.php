<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Mollie\Connect;

use InvalidArgumentException;
use LogicException;
use SensitiveParameter;

/**
 * The OAuth app a platform registered at Mollie, which merchants connect their own Mollie accounts to.
 *
 * The client secret never shows: a debugger, `var_dump` and `print_r` see it masked, and the object refuses
 * to be serialized, so it cannot reach a queue payload, a cache or a session.
 */
final readonly class MollieOAuthApp
{
    /**
     * @param  string  $clientId  the app's client id, `app_…`
     * @param  string  $clientSecret  the app's client secret
     * @param  string  $redirectUri  the address Mollie sends the merchant back to with a code, as registered with the app
     *
     * @throws InvalidArgumentException when a value is empty or the client id has another shape
     */
    public function __construct(
        public string $clientId,
        #[SensitiveParameter]
        public string $clientSecret,
        public string $redirectUri,
    ) {
        if (preg_match('/^app_\w+$/', $clientId) !== 1) {
            throw new InvalidArgumentException("A Mollie OAuth app is named by its client id, which begins `app_`, not `{$clientId}`.");
        }

        if (trim($clientSecret) === '') {
            throw new InvalidArgumentException("The Mollie OAuth app `{$clientId}` has no client secret.");
        }

        $address = parse_url($redirectUri);

        if (! is_array($address) || ($address['scheme'] ?? null) !== 'https' || ($address['host'] ?? '') === '') {
            throw new InvalidArgumentException("The Mollie OAuth app `{$clientId}` sends merchants back to an address that is no https URL.");
        }
    }

    /**
     * What a debugger shows: everything but the client secret.
     *
     * @return array{clientId: string, clientSecret: string, redirectUri: string}
     */
    public function __debugInfo(): array
    {
        return ['clientId' => $this->clientId, 'clientSecret' => '(hidden)', 'redirectUri' => $this->redirectUri];
    }

    /**
     * Refused: a serialized app would carry its client secret into a queue, a cache or a session.
     *
     * @return array<string, mixed>
     *
     * @throws LogicException always
     */
    public function __serialize(): array
    {
        throw new LogicException('A MollieOAuthApp carries its client secret and is never serialized.');
    }

    /**
     * Refused as well, so a payload written by hand cannot build an app past the checks of the constructor.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws LogicException always
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('A MollieOAuthApp is never unserialized.');
    }
}
