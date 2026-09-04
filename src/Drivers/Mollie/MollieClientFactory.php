<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Mollie;

use Illuminate\Support\Facades\Config;
use Mollie\Api\MollieApiClient;
use Pushery\Billing\Exceptions\MollieNotConfigured;
use Throwable;

/**
 * Builds the Mollie API client, or says exactly why it cannot.
 *
 * A factory rather than a container binding built inline, because the two failure modes have to be
 * describable: the SDK is a suggestion, so "the class is not there" is a legitimate state the package must
 * be able to report rather than a fatal error out of the autoloader.
 *
 * The client class is injectable ONLY so the missing-package path is reachable in a test. It is not a
 * seam for swapping in another client — that is what the driver's rails are for — and the default is the
 * real class, so nothing about the shipped behavior depends on the argument.
 */
final readonly class MollieClientFactory
{
    /** @param class-string|string $clientClass */
    public function __construct(private string $clientClass = MollieApiClient::class) {}

    public function make(): MollieApiClient
    {
        if (! class_exists($this->clientClass)) {
            throw MollieNotConfigured::missingPackage($this->clientClass);
        }

        $key = Config::get('billing.mollie.api_key');

        if (! is_string($key) || trim($key) === '') {
            throw MollieNotConfigured::missingApiKey();
        }

        $client = new MollieApiClient;

        // The SDK validates the key's shape itself and throws its own exception. Caught and translated:
        // its message arrives with a stack trace from inside a third-party library and names none of our
        // settings, which sends whoever reads it looking in the wrong package.
        try {
            $client->setToken(trim($key));
        } catch (Throwable $rejected) {
            throw MollieNotConfigured::malformedApiKey($rejected->getMessage());
        }

        return $client;
    }
}
