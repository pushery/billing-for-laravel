<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Mollie;

use Composer\InstalledVersions;
use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Config;
use Mollie\Api\Http\LinearRetryStrategy;
use Mollie\Api\MollieApiClient;
use Pushery\Billing\Exceptions\MollieNotConfigured;
use Pushery\Billing\ValueObjects\PaymentAccount;
use Throwable;

/**
 * Builds the Mollie API client, or says exactly why it cannot.
 *
 * A factory rather than a container binding built inline, because the two failure modes have to be
 * describable: the SDK is a suggestion, so "the class is not there" is a legitimate state the package must
 * be able to report rather than a fatal error out of the autoloader.
 *
 * The client class is injectable only so the missing-package path is reachable in a test. It is not a
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
     * @param  bool|null  $servingRequest  Whether the client is built inside a web request. Injectable for the same
     *                                     reason again: a test runs in the console, and this is how the request
     *                                     set becomes reachable in one. Null asks the application.
     */
    public function __construct(
        private string $clientClass = MollieApiClient::class,
        private string $packageName = 'pushery/billing-for-laravel',
        private ?bool $servingRequest = null,
    ) {}

    public function make(): MollieApiClient
    {
        $this->requirePackage();

        $key = Config::get('billing.mollie.api_key');

        if (! is_string($key) || trim($key) === '') {
            throw MollieNotConfigured::missingApiKey();
        }

        // Through Laravel's HTTP client, not around it. Without an adapter the SDK picks its own
        // transport — Guzzle if the class is there, raw cURL otherwise — and both are invisible to the
        // application: `Http::preventStrayRequests()` does not catch the call, global middleware does not
        // see it, and the timeouts come from the SDK's defaults rather than from the host's configuration.
        $client = new MollieApiClient(new LaravelHttpMollieAdapter);

        // The SDK validates the key's shape itself and throws its own exception. Caught and translated:
        // its message arrives with a stack trace from inside a third-party library and names none of our
        // settings, which sends whoever reads it looking in the wrong package.
        try {
            $client->setToken(trim($key));
        } catch (Throwable $rejected) {
            throw MollieNotConfigured::malformedApiKey($rejected->getMessage());
        }

        return $this->announced($client);
    }

    /**
     * A client for one payment account: its API key or its OAuth access token, in the account's test mode.
     *
     * An API key picks its mode by its prefix, `test_` or `live_`, and the SDK sends no test flag with it. An
     * account whose `testmode` disagrees with its key is refused, because the key would win and the account
     * would charge in the mode nobody asked for. An access token carries no mode, so the client is told the
     * account's.
     *
     * Its timeouts and retries come from `billing.mollie.account_http`: the `request` set inside a web request,
     * the `background` set everywhere else.
     *
     * @throws MollieNotConfigured when the SDK is missing, the credential is no key or token, or its mode disagrees with the account
     */
    public function forAccount(PaymentAccount $account): MollieApiClient
    {
        $this->requirePackage();

        $credential = trim($account->credential);
        $http = $this->accountHttp();
        $client = new MollieApiClient(new LaravelHttpMollieAdapter(timeout: $http['timeout'], connectTimeout: $http['connect_timeout']));
        $client->setRetryStrategy(new LinearRetryStrategy($http['retries'], $http['retry_delay_ms']));

        // The SDK's own message is left out here: it may quote the credential, and this exception reaches logs.
        try {
            $client->setToken($credential);
        } catch (Throwable) {
            throw MollieNotConfigured::malformedAccountCredential($account->key);
        }

        if (! str_starts_with($credential, 'access_') && str_starts_with($credential, 'test_') !== $account->testmode) {
            throw MollieNotConfigured::accountModeDisagrees($account->key, $account->testmode);
        }

        $client->test($account->testmode);

        return $this->announced($client);
    }

    /**
     * The timeouts and retries of an account's client here, read leniently: a value that is no whole number
     * keeps the application's HTTP default, or the SDK's five retries one second apart.
     *
     * @return array{timeout: int|null, connect_timeout: int|null, retries: int, retry_delay_ms: int}
     */
    private function accountHttp(): array
    {
        $set = Config::get('billing.mollie.account_http.'.($this->insideAWebRequest() ? 'request' : 'background'));
        $set = is_array($set) ? $set : [];
        $timeout = $set['timeout'] ?? null;
        $connectTimeout = $set['connect_timeout'] ?? null;
        $retries = $set['retries'] ?? null;
        $delay = $set['retry_delay_ms'] ?? null;

        return [
            'timeout' => is_int($timeout) && $timeout > 0 ? $timeout : null,
            'connect_timeout' => is_int($connectTimeout) && $connectTimeout > 0 ? $connectTimeout : null,
            'retries' => is_int($retries) && $retries >= 0 ? $retries : 5,
            'retry_delay_ms' => is_int($delay) && $delay >= 0 ? $delay : 1000,
        ];
    }

    /** Whether the client is built inside a web request, as the application tells it. */
    private function insideAWebRequest(): bool
    {
        if ($this->servingRequest !== null) {
            return $this->servingRequest;
        }

        $application = Container::getInstance();

        return $application instanceof Application && ! $application->runningInConsole();
    }

    /** @throws MollieNotConfigured when the SDK is not installed */
    private function requirePackage(): void
    {
        if (! class_exists($this->clientClass)) {
            throw MollieNotConfigured::missingPackage($this->clientClass);
        }
    }

    /** The client with this package's version string, where Composer can tell it. */
    private function announced(MollieApiClient $client): MollieApiClient
    {
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
     * Derived, never typed. Mollie's own Laravel package keeps its version in a hand-maintained constant,
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
        // One condition rather than two, and that is about reachability rather than brevity. Two separate
        // early returns are two lines no test can enter -- Composer's runtime is always there in a suite
        // that Composer installed -- and an unreachable line cannot be told apart from a wrong one.
        if (! class_exists(InstalledVersions::class) || ! InstalledVersions::isInstalled($this->packageName)) {
            return null;
        }

        $version = InstalledVersions::getPrettyVersion($this->packageName);

        return $version === null ? null : 'PusheryBilling/'.$version;
    }
}
