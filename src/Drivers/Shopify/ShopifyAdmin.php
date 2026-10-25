<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Shopify;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Pushery\Billing\Contracts\ShopifyAccessTokens;
use Pushery\Billing\Exceptions\ShopifyNotConfigured;
use Pushery\Billing\Exceptions\ShopifyRequestFailed;
use stdClass;

/**
 * The Admin GraphQL API of one shop, as the billing of an app sees it: a query or a mutation, sent with the
 * shop's offline access token, answered with its `data`.
 *
 * Every failure is one exception, whatever its layer: the shop out of reach, an HTTP status other than 200, an
 * error of the GraphQL layer, or a mutation that answers with `userErrors`. A caller decides what the failure
 * means for its act, and none of them goes on with half an answer.
 *
 * The shop is named by its permanent `*.myshopify.com` domain. The domain is the host the request goes to, so a
 * value that is not one is refused before anything is sent.
 */
final readonly class ShopifyAdmin
{
    /** Seconds a request may take. Billing calls are made while a merchant waits for a page, or inside a webhook job. */
    private const int TIMEOUT = 15;

    public function __construct(
        private Container $container,
        private Repository $config,
    ) {}

    /** Whether a value is the permanent domain of a Shopify shop. */
    public static function isShop(string $shop): bool
    {
        return preg_match('/\A[a-z0-9][a-z0-9-]*\.myshopify\.com\z/', $shop) === 1;
    }

    /**
     * Send a query and return its `data`.
     *
     * @param  array<string, mixed>  $variables
     * @return array<array-key, mixed>
     *
     * @throws ShopifyRequestFailed
     * @throws ShopifyNotConfigured when the application binds no access tokens
     */
    public function query(string $shop, string $document, array $variables = []): array
    {
        if (! self::isShop($shop)) {
            throw ShopifyRequestFailed::notAShop($shop);
        }

        try {
            $response = Http::acceptJson()
                ->withHeaders(['X-Shopify-Access-Token' => $this->tokens()->for($shop)])
                ->timeout(self::TIMEOUT)
                ->post($this->endpoint($shop), [
                    'query' => $document,
                    // An empty array is sent as a JSON list, which Shopify refuses as variables.
                    'variables' => $variables === [] ? new stdClass : $variables,
                ]);
        } catch (ConnectionException $exception) {
            throw ShopifyRequestFailed::unreachable($shop, $exception);
        }

        if ($response->status() !== 200) {
            throw ShopifyRequestFailed::status($shop, $response->status());
        }

        $body = $response->json();
        $errors = is_array($body) ? ($body['errors'] ?? null) : null;

        if (is_array($errors) && $errors !== []) {
            throw ShopifyRequestFailed::graphql($shop, $this->messages($errors));
        }

        $data = is_array($body) ? ($body['data'] ?? null) : null;

        return is_array($data) ? $data : [];
    }

    /**
     * Send a mutation and return the payload of its one field, refused where Shopify answered with `userErrors`.
     *
     * @param  array<string, mixed>  $variables
     * @return array<array-key, mixed>
     *
     * @throws ShopifyRequestFailed
     * @throws ShopifyNotConfigured when the application binds no access tokens
     */
    public function mutate(string $shop, string $document, array $variables, string $field): array
    {
        $payload = $this->query($shop, $document, $variables)[$field] ?? null;

        if (! is_array($payload)) {
            throw ShopifyRequestFailed::noPayload($shop, $field);
        }

        $errors = $payload['userErrors'] ?? [];

        if (is_array($errors) && $errors !== []) {
            throw ShopifyRequestFailed::userErrors($shop, $field, $this->messages($errors));
        }

        return $payload;
    }

    private function endpoint(string $shop): string
    {
        $version = $this->config->get('billing.shopify.api_version');

        return sprintf('https://%s/admin/api/%s/graphql.json', $shop, is_string($version) && trim($version) !== '' ? trim($version) : '2026-10');
    }

    private function tokens(): ShopifyAccessTokens
    {
        if (! $this->container->bound(ShopifyAccessTokens::class)) {
            throw ShopifyNotConfigured::noAccessTokens();
        }

        return $this->container->make(ShopifyAccessTokens::class);
    }

    /**
     * The `message` of each error, whether GraphQL or a mutation reported it.
     *
     * @param  array<array-key, mixed>  $errors
     * @return list<string>
     */
    private function messages(array $errors): array
    {
        $messages = [];

        foreach ($errors as $error) {
            $message = is_array($error) ? ($error['message'] ?? null) : null;

            $messages[] = is_string($message) ? $message : 'an error without a message';
        }

        return $messages;
    }
}
