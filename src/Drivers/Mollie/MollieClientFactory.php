<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Mollie;

use Composer\InstalledVersions;
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
    /**
     * @param  class-string|string  $clientClass
     * @param  string  $packageName  Injectable for the same reason as the class above, and only that reason:
     *                               it is how the "no version to report" path becomes reachable in a test.
     *                               The default is this package, and nothing shipped passes anything else.
     */
    public function __construct(
        private string $clientClass = MollieApiClient::class,
        private string $packageName = 'pushery/billing-for-laravel',
    ) {}

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

        if (($version = $this->versionString()) !== null) {
            $client->addVersionString($version);
        }

        return $client;
    }

    /**
     * Who is calling, for Mollie's integration telemetry.
     *
     * The SDK already announces itself and the PHP version; this adds the only part it cannot know -- that
     * the calls come from this package. It is what makes us distinguishable from "some PHP SDK" if this
     * integration ever shows up in Mollie's own numbers or in a support thread.
     *
     * DERIVED, never typed. Mollie's own Laravel package keeps its version in a hand-maintained constant,
     * and at the time of writing that constant says 4.0.2 while the released tag is v4.1.0 -- so their
     * telemetry reports a version that does not exist. A constant in a file somebody has to remember to
     * edit is a constant that drifts, and the drift is invisible precisely because nothing reads it back.
     *
     * Guarded by class_exists rather than by requiring composer-runtime-api: the constraint block of this
     * package decides who may install it, and adding an entry there to send a telemetry header would be a
     * poor trade. Without Composer's runtime the header is simply omitted.
     */
    private function versionString(): ?string
    {
        // ONE condition rather than two, and that is about reachability rather than brevity. Two separate
        // early returns are two lines no test can enter -- Composer's runtime is always there in a suite
        // that Composer installed -- and an unreachable line cannot be told apart from a wrong one.
        if (! class_exists(InstalledVersions::class) || ! InstalledVersions::isInstalled($this->packageName)) {
            return null;
        }

        $version = InstalledVersions::getPrettyVersion($this->packageName);

        return $version === null ? null : 'PusheryBilling/'.$version;
    }
}
