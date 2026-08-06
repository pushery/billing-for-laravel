<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Pushery\Billing\Enums\WebhookEventState;

/**
 * One verified provider webhook delivery, unique per (provider, ACCOUNT REFERENCE, event_id) so a
 * redelivery is recognized rather than re-processed. It keeps the raw payload, which is what makes a
 * delivery REPLAYABLE: an effect that failed can be re-driven from the stored payload without asking the
 * provider to redeliver.
 *
 * The account is part of the identity, and it was added after the fact — the index started as
 * (provider, event_id) and a migration replaced it. On a Connect platform the same package receives
 * deliveries for the platform's own account and for every connected one, and the reference (empty for the
 * platform's own) is what keeps two accounts' deliveries from being read as redeliveries of each other.
 *
 * @property string $provider
 * @property string $account_reference
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
        'provider', 'account_reference', 'event_id', 'type', 'payload', 'status', 'last_error', 'handled_at',
        'owner_type', 'owner_id',
    ];

    /**
     * The same defaults the schema carries, so a row that was just created reads like one that was read back.
     *
     * Without them a model created without these columns holds null for each, while the row the database
     * stores holds the value — a disagreement that lasts only until somebody re-reads, which is exactly why
     * it hides. Held against the migration by ModelSchemaDefaultsTest.
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'status' => 'pending',
        'account_reference' => '',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'payload' => 'array',
        'status' => WebhookEventState::class,
        'handled_at' => 'datetime',
    ];
}
