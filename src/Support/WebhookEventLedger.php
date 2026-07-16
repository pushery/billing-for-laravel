<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Pushery\Billing\Enums\WebhookEventState;
use Pushery\Billing\Models\BillingWebhookEvent;

/**
 * Records each verified provider delivery, payload and all. Keeping the raw payload is what makes an
 * effect REPLAYABLE: when one fails, the package can re-drive it from what the provider already sent,
 * instead of hoping the provider will redeliver (which it stops doing after its own retry window).
 *
 * Recording is idempotent on (provider, event_id): a redelivery of the same event returns the existing
 * row rather than a second one.
 */
final class WebhookEventLedger
{
    /**
     * Record a verified delivery (or return the row a redelivery already created).
     *
     * @param  array<array-key, mixed>  $payload
     */
    public function record(string $provider, string $eventId, string $type, array $payload): BillingWebhookEvent
    {
        $delivery = BillingWebhookEvent::query()->firstOrCreate(
            ['provider' => $provider, 'event_id' => $eventId],
            ['type' => $type, 'payload' => $payload, 'status' => WebhookEventState::Pending],
        );

        // A redelivery of an event recorded before payloads were kept (or by an older version) still
        // needs its payload, or it could never be replayed.
        if ($delivery->payload === null) {
            $delivery->forceFill(['payload' => $payload])->save();
        }

        return $delivery;
    }

    /**
     * Attribute a delivery to the owner it is about.
     *
     * The stored payload carries that owner's personal data — their email, their name, their billing
     * address. Without this, the delivery is personal data nobody can reach: an erasure request could not
     * find it, and it would sit there until the retention clock swept it. With it, an erasure scrubs
     * exactly the payloads that belong to the person asking.
     */
    public function attachOwner(BillingWebhookEvent $delivery, Model $owner): void
    {
        if ($delivery->owner_type !== null) {
            return;
        }

        $delivery->forceFill([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
        ])->save();
    }

    public function markHandled(BillingWebhookEvent $delivery): void
    {
        $delivery->forceFill([
            'status' => WebhookEventState::Handled,
            'last_error' => null,
            'handled_at' => Carbon::now(),
        ])->save();
    }

    public function markFailed(BillingWebhookEvent $delivery, string $error): void
    {
        $delivery->forceFill(['status' => WebhookEventState::Failed, 'last_error' => $error])->save();
    }
}
