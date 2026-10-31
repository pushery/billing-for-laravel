<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use Closure;
use Stripe\ApiRequestor;
use Stripe\HttpClient\CurlClient;
use Stripe\StripeClient;
use Stripe\StripeObject;
use Stripe\Util\RequestOptions;

/**
 * A Stripe client that sends its requests with timeouts of its own.
 *
 * The Stripe SDK takes no HTTP client per client: every request goes through the one client `ApiRequestor`
 * holds for the whole process, a `CurlClient` with 80 seconds for a request and 30 for a connection unless
 * the application set other numbers. This client hands each of its requests a copy of that `CurlClient`
 * with its own timeouts, and puts the process's client back when the request ends, however it ends. An
 * application that set an HTTP client of another kind keeps it, with its own timeouts.
 *
 * Every service call goes through `request()`, lists and searches included. `rawRequest()` and a streamed
 * response keep the process's own clients: this package calls neither.
 */
final class TimedStripeClient extends StripeClient
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(array $config, private readonly ?int $timeout, private readonly ?int $connectTimeout)
    {
        parent::__construct($config);
    }

    /**
     * @param  'delete'|'get'|'post'  $method
     * @param  string  $path
     * @param  array<array-key, mixed>|null  $params
     * @param  array<array-key, mixed>|RequestOptions|null  $opts
     */
    public function request(mixed $method, mixed $path, mixed $params, mixed $opts): StripeObject
    {
        return $this->withTimeouts(fn (): StripeObject => parent::request($method, $path, $params ?? [], $opts ?? []));
    }

    /**
     * Runs $request with this client's timeouts on a copy of the process's `CurlClient`.
     *
     * @template T
     *
     * @param  Closure(): T  $request
     * @return T
     */
    private function withTimeouts(Closure $request): mixed
    {
        $process = ApiRequestor::httpClient();

        if (! $process instanceof CurlClient || ($this->timeout === null && $this->connectTimeout === null)) {
            return $request();
        }

        $timed = clone $process;

        if ($this->timeout !== null) {
            $timed->setTimeout($this->timeout);
        }

        if ($this->connectTimeout !== null) {
            $timed->setConnectTimeout($this->connectTimeout);
        }

        ApiRequestor::setHttpClient($timed);

        try {
            return $request();
        } finally {
            ApiRequestor::setHttpClient($process);
        }
    }
}
