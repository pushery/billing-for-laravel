<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Mollie;

use Composer\InstalledVersions;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Mollie\Api\Contracts\HttpAdapterContract;
use Mollie\Api\Exceptions\RetryableNetworkRequestException;
use Mollie\Api\Http\PendingRequest;
use Mollie\Api\Http\Response;
use Mollie\Api\Utils\Factories;

/**
 * Sends Mollie's traffic through Laravel's HTTP client instead of around it.
 *
 * ## What was going around it
 *
 * The SDK picks its own transport when none is given — Guzzle if the class is there, raw cURL otherwise.
 * Both work, and both are invisible to the application:
 *
 * - `Http::preventStrayRequests()` in an application's own suite did NOT catch a Mollie call. That is the
 *   single assurance the method exists to give, and this package was the exception to it.
 * - Global `Http` middleware — logging, tracing, a proxy, header enrichment — saw every outbound request
 *   except this one.
 * - Timeouts, retries and pooling came from the SDK's defaults rather than from the application's
 *   configuration.
 *
 * None of that is a defect that breaks anything. It is a package quietly opting out of the host's own
 * instrumentation, which the host cannot see and therefore cannot correct.
 *
 * ## Why this costs no new dependency
 *
 * `illuminate/http` is already required, and this package already reaches for the `Http` facade in four
 * other places. The pattern was ours everywhere except at the one client that talks to a payment provider.
 *
 * ## What is deliberately NOT caught
 *
 * Only {@see ConnectionException} is translated. `Http::preventStrayRequests()` raises a
 * `StrayRequestException`, which extends `RuntimeException` and NOT `ConnectionException` — so it travels
 * up untouched, which is the whole point: catching it here would restore exactly the blindness this class
 * removes, while looking like careful error handling.
 *
 * An HTTP error status is not an exception either. `http_errors` stays off and the response is handed to
 * the SDK as it arrived, because Mollie's own error bodies are what its exceptions are built from —
 * swallowing a 422 here would replace a precise "this field is wrong" with a generic transport failure.
 */
final readonly class LaravelHttpMollieAdapter implements HttpAdapterContract
{
    /**
     * @param  list<string>  $versionPackages  Which package's version identifies the transport, most
     *                                         specific first. Injectable for ONE reason, the same one the
     *                                         client factory states: it is how the "nothing to report" path
     *                                         becomes reachable in a test. Nothing shipped passes anything
     *                                         else, and this is not a seam for changing what is reported.
     */
    public function __construct(private array $versionPackages = ['illuminate/http', 'laravel/framework']) {}

    public function factories(): Factories
    {
        $factory = new HttpFactory;

        return new Factories($factory, $factory, $factory, $factory);
    }

    /**
     * @throws RetryableNetworkRequestException when the request never reached Mollie
     */
    public function sendRequest(PendingRequest $pendingRequest): Response
    {
        $request = $pendingRequest->createPsrRequest();

        $headers = [];

        foreach ($request->getHeaders() as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }

        try {
            $response = Http::withHeaders($headers)
                // Off on purpose: an error STATUS is an answer from Mollie, and its body is what the SDK
                // builds its exceptions from. Letting Laravel throw here would replace "this field is
                // wrong" with a transport failure carrying none of that.
                ->withOptions(['http_errors' => false])
                ->send($request->getMethod(), (string) $request->getUri(), [
                    'body' => (string) $request->getBody(),
                ]);
        } catch (ConnectionException $unreachable) {
            // Retryable, and the distinction matters to the SDK: a request that never arrived may be sent
            // again safely, while one that did must not be. Only a connection failure qualifies.
            throw new RetryableNetworkRequestException($pendingRequest, $unreachable->getMessage());
        }

        return new Response($response->toPsrResponse(), $request, $pendingRequest);
    }

    /**
     * The transport's own version, for Mollie's telemetry.
     *
     * Names Laravel rather than Guzzle, which is what the SDK would otherwise report. That is the honest
     * answer: the request went through Laravel's client, with the application's middleware and options on
     * it, and if this integration ever shows up in a support thread that is the fact worth having.
     *
     * ## Read from Composer, never from `app()`
     *
     * `app()` is a FOUNDATION-only helper, and this package declares sixteen `illuminate/*` components and
     * not `laravel/framework` — a stance held at zero Foundation helper calls across the whole shipped tree
     * and guarded by `LeanDependencyContractTest`. One call here would have been the first, and the guard
     * caught it. The version that actually matters is the transport's anyway: `illuminate/http` is what
     * carries the request, and it is a declared dependency rather than something inferred from what the
     * application happens to have installed.
     *
     * `null` when Composer's runtime is absent, which the interface allows and which is the honest answer —
     * the same guard the client factory uses for the same reason.
     */
    public function version(): ?string
    {
        // Both names, and the second is not a fallback for tidiness. `laravel/framework` REPLACES all 38
        // `illuminate/*` splits, so in a tree where the full framework is installed — which is every
        // application, and this package's own test tree through Testbench — Composer answers for the split
        // with a null VERSION even though it reports it as installed. Asking only for the split therefore
        // reports nothing precisely where the component is most certainly present.
        //
        // The Composer check sits inside the loop rather than above it, which is about reachability rather
        // than brevity: a separate early return is a line no test can enter, and the client factory next
        // door folds the same two conditions together for the same reason.
        foreach ($this->versionPackages as $package) {
            if (! class_exists(InstalledVersions::class) || ! InstalledVersions::isInstalled($package)) {
                continue;
            }

            $version = InstalledVersions::getPrettyVersion($package);

            if ($version !== null) {
                return 'Laravel/'.$version;
            }
        }

        return null;
    }
}
