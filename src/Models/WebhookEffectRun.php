<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Pushery\Billing\Enums\WebhookEventState;

/**
 * The record of one effect run against one reference: did this effect already do its work for this
 * thing? A Handled row is the dedup that keeps at-least-once provider delivery from running an effect
 * twice; a Failed row is work the package knows it still owes, and is what a replay picks back up.
 *
 * @property string $provider
 * @property string $reference
 * @property string $effect
 * @property ?int $delivery_id
 * @property WebhookEventState $status
 * @property int $attempts
 * @property ?string $last_error
 * @property ?Carbon $handled_at
 */
final class WebhookEffectRun extends Model
{
    protected $table = 'billing_webhook_effect_runs';

    /** @var list<string> */
    protected $fillable = [
        'provider', 'reference', 'effect', 'delivery_id', 'status', 'attempts', 'last_error', 'handled_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'status' => WebhookEventState::class,
        'attempts' => 'integer',
        'handled_at' => 'datetime',
    ];
}
