<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Shopify;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Pushery\Billing\Contracts\WebhookVerifier;

/**
 * Authenticates a Shopify webhook by its `X-Shopify-Hmac-Sha256` header.
 *
 * Shopify signs every delivery: the header is the Base64 of an HMAC-SHA256 over the raw body, keyed with the
 * client secret of the app. The comparison is made on the raw bytes in constant time, against each configured
 * secret, so a secret can be rotated while both are live. A request without the header, or with one that no
 * secret produces, is refused, and so is every request while no secret is configured: an endpoint that cannot
 * check a signature does not decide that a subscription became active.
 */
final readonly class ShopifyWebhookVerifier implements WebhookVerifier
{
    public const string SIGNATURE_HEADER = 'X-Shopify-Hmac-Sha256';

    public function __construct(private Repository $config) {}

    public function verify(Request $request): bool
    {
        $signature = $request->header(self::SIGNATURE_HEADER);

        if (! is_string($signature) || trim($signature) === '') {
            return false;
        }

        $body = $request->getContent();

        return array_any(
            $this->secrets(),
            static fn (string $secret): bool => hash_equals(base64_encode(hash_hmac('sha256', $body, $secret, true)), trim($signature)),
        );
    }

    /**
     * The configured client secrets. Written comma-separated in the environment while a rotation runs, so both
     * the old and the new one are accepted until the old one is removed.
     *
     * @return list<string>
     */
    private function secrets(): array
    {
        $configured = $this->config->get('billing.shopify.client_secret');

        if (is_string($configured)) {
            $configured = explode(',', $configured);
        }

        if (! is_array($configured)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $secret): string => is_string($secret) ? trim($secret) : '', $configured),
            static fn (string $secret): bool => $secret !== '',
        ));
    }
}
