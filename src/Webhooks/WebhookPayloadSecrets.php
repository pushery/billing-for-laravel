<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks;

/**
 * The fields of a provider payload that must not be kept, removed before a delivery is stored.
 *
 * Stripe puts a `client_secret` on a PaymentIntent, a SetupIntent and an embedded Checkout Session, and its API
 * reference says of the field that it should not be stored, logged, or exposed to anyone other than the customer.
 * The delivery ledger keeps every payload for replay, and the data export hands those payloads to the person they
 * are about, so a secret kept there would be stored and handed on. No effect reads it: an effect works from the
 * event the mapper builds, and the mapper never reads the field, so a replay of a stripped payload runs the same
 * effects as the delivery did.
 *
 * Removed at any depth rather than at `data.object` alone, because an expanded object nests the same field one level
 * further in, and a key that is absent says so more plainly than a placeholder a later reader could take for a value.
 */
final class WebhookPayloadSecrets
{
    /** The keys removed wherever they appear. */
    private const array KEYS = ['client_secret'];

    /**
     * The payload without those keys.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public static function strip(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (in_array($key, self::KEYS, true)) {
                unset($payload[$key]);

                continue;
            }

            if (is_array($value)) {
                $payload[$key] = self::strip($value);
            }
        }

        return $payload;
    }

    /**
     * A payload as a database row holds it, a JSON string, without those keys.
     *
     * The string comes back unchanged when nothing was removed, and so does anything that is not a JSON object or
     * array, so a caller can tell from the answer alone whether a row needs writing.
     */
    public static function stripJson(mixed $json): mixed
    {
        if (! is_string($json)) {
            return $json;
        }

        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            return $json;
        }

        $stripped = self::strip($decoded);

        return $stripped === $decoded ? $json : json_encode($stripped, JSON_THROW_ON_ERROR);
    }
}
