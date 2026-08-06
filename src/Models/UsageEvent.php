<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Pushery\Billing\Enums\UsageEventState;

/**
 * One recorded unit-of-usage on its way to the provider that bills it.
 *
 * @property string $owner_type
 * @property int $owner_id
 * @property string $meter_key
 * @property ?string $provider_meter
 * @property int $quantity
 * @property int $prepaid_units
 * @property Carbon $occurred_at
 * @property string $period
 * @property string $identifier
 * @property ?string $source_key
 * @property UsageEventState $state
 * @property ?Carbon $reported_at
 * @property int $attempts
 * @property ?Carbon $next_attempt_at
 * @property ?string $last_error
 * @property ?int $rolled_up_into
 * @property bool $is_rollup
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class UsageEvent extends Model
{
    protected $table = 'billing_usage_events';

    /** @var list<string> */
    protected $fillable = [
        'owner_type', 'owner_id', 'meter_key', 'provider_meter', 'quantity', 'prepaid_units', 'occurred_at', 'period',
        'identifier', 'source_key', 'state', 'reported_at', 'attempts', 'next_attempt_at',
        'last_error', 'rolled_up_into', 'is_rollup',
    ];

    /**
     * The same defaults the schema carries, so a row that was just created reads like one that was read back.
     *
     * Without them a model created without these columns holds null for each, while the row the database
     * stores holds the value — a disagreement that lasts only until somebody re-reads, which is exactly why
     * it hides. Held against the migration by ModelSchemaDefaultsTest.
     *
     * @var array<string, bool|int|string>
     */
    protected $attributes = [
        'state' => 'pending',
        'attempts' => 0,
        'is_rollup' => false,
        'prepaid_units' => 0,
    ];

    /** @var array<string, string> */
    protected $casts = [
        'quantity' => 'integer',
        'prepaid_units' => 'integer',
        'attempts' => 'integer',
        'rolled_up_into' => 'integer',
        'is_rollup' => 'boolean',
        'occurred_at' => 'datetime',
        'reported_at' => 'datetime',
        'next_attempt_at' => 'datetime',
        'state' => UsageEventState::class,
    ];
}
