<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks;

use Illuminate\Http\Request;
use Pushery\Billing\Models\BillingWebhookEvent;

/**
 * The request a provider sent, rebuilt from the payload a delivery stored.
 *
 * `billing:webhooks:replay` maps it again, and a delivery recorded before the receiver named payments is named from it,
 * each by the mapper that read the request live, so a stored payload is never read along a second path that could drift
 * from the live one. A ping that was posted as a form is stored as its fields and comes back as a JSON body, which a
 * mapper reads the same way through `Request::input()`.
 *
 * @internal
 */
final class StoredDeliveryRequest
{
    public static function of(BillingWebhookEvent $delivery): Request
    {
        return Request::create(
            '/',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($delivery->payload ?? [], JSON_THROW_ON_ERROR),
        );
    }
}
