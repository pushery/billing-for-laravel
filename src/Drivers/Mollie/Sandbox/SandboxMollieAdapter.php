<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Mollie\Sandbox;

use GuzzleHttp\Psr7\HttpFactory;
use JsonException;
use Mollie\Api\Contracts\HttpAdapterContract;
use Mollie\Api\Http\PendingRequest;
use Mollie\Api\Http\Response;
use Mollie\Api\Utils\Factories;
use Psr\Http\Message\RequestInterface;

/**
 * The transport of the Mollie client while the sandbox answers for Mollie: every request the SDK would send to Mollie
 * is answered by {@see SandboxMollie} instead, as the JSON Mollie answers with, so the SDK builds the same resources
 * from it and raises the same exceptions.
 *
 * A mutating request repeated under its `Idempotency-Key` gets the answer the first one got, as at Mollie, which is
 * what makes a retried charge collapse onto the first one rather than charging twice.
 */
final readonly class SandboxMollieAdapter implements HttpAdapterContract
{
    public function __construct(private SandboxMollie $mollie) {}

    public function factories(): Factories
    {
        $factory = new HttpFactory;

        return new Factories($factory, $factory, $factory, $factory);
    }

    public function sendRequest(PendingRequest $pendingRequest): Response
    {
        $request = $pendingRequest->createPsrRequest();
        $method = strtoupper($request->getMethod());
        $path = $this->pathOf($request);
        $key = trim($request->getHeaderLine('Idempotency-Key'));
        $remembered = $key !== '' && $method !== 'GET' ? $this->mollie->answered("{$method} {$path} {$key}") : null;

        if ($remembered !== null) {
            return $this->respond($request, $pendingRequest, $remembered['status'], $remembered['body']);
        }

        try {
            [$status, $object] = $this->answer($method, $path, $this->payloadOf($request));
        } catch (SandboxRefusal $refusal) {
            [$status, $object] = [$refusal->status, $this->error($refusal->status, $refusal->getMessage())];
        }

        $body = $object === null ? '' : json_encode($object, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        if ($key !== '' && $method !== 'GET' && $status < 400) {
            $this->mollie->remember("{$method} {$path} {$key}", $status, $body);
        }

        return $this->respond($request, $pendingRequest, $status, $body);
    }

    public function version(): string
    {
        return 'Sandbox';
    }

    /**
     * The status and the object Mollie answers the request with, null for an answer without a body.
     *
     * The routes are the requests the driver sends for the install's own payments. The ids in a path are read as
     * placeholders, so `payments/tr_sbx000001/refunds` is the route `payments/{}/refunds`.
     *
     * @param  array<string, mixed>  $payload
     * @return array{int, array<string, mixed>|null}
     *
     * @throws SandboxRefusal
     */
    private function answer(string $method, string $path, array $payload): array
    {
        $segments = explode('/', $path);
        $route = implode('/', array_map(static fn (string $segment, int $index): string => $index % 2 === 1 ? '{}' : $segment, $segments, array_keys($segments)));
        $id = $segments[1] ?? '';

        return match ("{$method} {$route}") {
            'POST customers' => [201, $this->mollie->createCustomer($payload)],
            'DELETE customers/{}' => $this->deleted(fn () => $this->mollie->deleteCustomer($this->object($id, 'customer'))),
            'POST customers/{}/mandates' => [201, $this->mollie->createMandate($this->idOf($this->object($id, 'customer')), $payload)],
            'GET customers/{}/mandates/{}' => [200, $this->object($segments[3] ?? '', 'mandate')],
            'DELETE customers/{}/mandates/{}' => $this->deleted(fn () => $this->mollie->revokeMandate($this->object($segments[3] ?? '', 'mandate'))),
            'POST payments' => [201, $this->mollie->createPayment($payload)],
            'GET payments/{}' => [200, $this->object($id, 'payment')],
            'DELETE payments/{}' => [200, $this->mollie->cancel($this->object($id, 'payment'))],
            'POST payments/{}/refunds' => [201, $this->mollie->refund($this->object($id, 'payment'), $payload)],
            'GET payments/{}/refunds' => [200, $this->mollie->page('refunds', 'refunds:'.$this->idOf($this->object($id, 'payment')), $path)],
            'GET refunds' => [200, $this->mollie->page('refunds', 'refunds', 'refunds')],
            'GET chargebacks' => [200, $this->mollie->pageOf('chargebacks', [], 'chargebacks')],
            default => throw SandboxRefusal::unsupported($method, $path),
        };
    }

    /** @param  array<string, mixed>  $object */
    private function idOf(array $object): string
    {
        return is_string($object['id'] ?? null) ? $object['id'] : '';
    }

    /**
     * The object of this kind stored under the id.
     *
     * @return array<string, mixed>
     *
     * @throws SandboxRefusal when the sandbox issued no such object
     */
    private function object(string $id, string $resource): array
    {
        $object = $this->mollie->find($id);

        if ($object === null || ($object['resource'] ?? null) !== $resource) {
            throw SandboxRefusal::notFound("{$resource} with the id {$id}");
        }

        return $object;
    }

    /**
     * @param  callable(): void  $delete
     * @return array{int, null}
     */
    private function deleted(callable $delete): array
    {
        $delete();

        return [204, null];
    }

    /** @return array<string, mixed> */
    private function error(int $status, string $detail): array
    {
        return [
            'status' => $status,
            'title' => $status === 404 ? 'Not Found' : 'Unprocessable Entity',
            'detail' => $detail,
            '_links' => ['documentation' => ['href' => 'https://docs.mollie.com/overview/handling-errors', 'type' => 'text/html']],
        ];
    }

    /** The path below the API's version, `payments/tr_sbx000001` for `/v2/payments/tr_sbx000001`. */
    private function pathOf(RequestInterface $request): string
    {
        $path = trim($request->getUri()->getPath(), '/');

        return str_starts_with($path, 'v2/') ? substr($path, 3) : $path;
    }

    /**
     * The JSON body of the request, or nothing.
     *
     * @return array<string, mixed>
     */
    private function payloadOf(RequestInterface $request): array
    {
        try {
            $payload = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        $keyed = [];

        foreach (is_array($payload) ? $payload : [] as $field => $value) {
            $keyed[(string) $field] = $value;
        }

        return $keyed;
    }

    private function respond(RequestInterface $request, PendingRequest $pendingRequest, int $status, string $body): Response
    {
        $factory = new HttpFactory;
        $response = $factory->createResponse($status)->withHeader('Content-Type', 'application/hal+json');

        if ($body !== '') {
            $response = $response->withBody($factory->createStream($body));
        }

        return new Response($response, $request, $pendingRequest);
    }
}
