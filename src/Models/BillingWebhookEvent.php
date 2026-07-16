<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Pushery\Billing\Enums\WebhookEventState;

/**
 * One verified provider webhook delivery, unique per (provider, event_id) so a redelivery is recognized
 * rather than re-processed. It keeps the raw payload, which is what makes a delivery REPLAYABLE: an
 * effect that failed can be re-driven from the stored payload without asking the provider to redeliver.
 *
 * @property string $provider
 * @property string $event_id
 * @property string $type
 * @property ?array<array-key, mixed> $payload
 * @property WebhookEventState $status
 * @property ?string $last_error
 * @property ?Carbon $handled_at
 * @property ?string $owner_type
 * @property ?int $owner_id
 * @property ?Carbon $created_at
 */
final class BillingWebhookEvent extends Model
{
    protected $table = 'billing_webhook_events';

    /** @var list<string> */
    protected $fillable = [
        'provider', 'event_id', 'type', 'payload', 'status', 'last_error', 'handled_at', 'owner_type', 'owner_id',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'payload' => 'array',
        'status' => WebhookEventState::class,
        'handled_at' => 'datetime',
    ];
}
