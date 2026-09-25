<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Pushery\Billing\Casts\UtcDateTime;
use Pushery\Billing\Enums\SellerDataEscalationStage;
use Pushery\Billing\Enums\SellerDataMeasure;
use Pushery\Billing\Models\Concerns\Replaceable;

/**
 * One episode of a seller's record staying incomplete: when it began, how far it went and how it ended.
 *
 * The stage is stored, not derived from the dates. What has to be answerable later, to the seller or to an
 * authority, is what the platform did and when, and the row says that directly: when each reminder went
 * out, which channels it was handed to, which measure applied from when, and why that measure ended.
 *
 * An episode that is still open has no `resolved_at`. A seller has at most one open episode; a record that
 * becomes incomplete again after being completed opens a new one, so every episode stays readable on its
 * own.
 *
 * @property int $id
 * @property ?string $merchant_type
 * @property int|string|null $merchant_id
 * @property SellerDataEscalationStage $stage
 * @property Carbon $incomplete_since
 * @property list<string> $missing_fields
 * @property bool $missing_required
 * @property ?Carbon $first_reminded_at
 * @property ?Carbon $second_reminded_at
 * @property ?list<array{reminder: string, channel: string, recipient: string, at: string}> $deliveries
 * @property ?SellerDataMeasure $measure
 * @property ?Carbon $measure_started_at
 * @property ?list<array{measure: string, started_at: string, ended_at: string, reason: string}> $ended_measures
 * @property ?Carbon $resolved_at
 * @property ?Carbon $merchant_erased_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class SellerDataEscalationEpisode extends Model
{
    use Replaceable;

    protected $table = 'billing_seller_data_escalations';

    /**
     * The same defaults the schema carries, so a row that was just created reads like one that was read back.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'missing_required' => false,
    ];

    /** @var list<string> */
    protected $fillable = [
        'merchant_type', 'merchant_id', 'stage', 'incomplete_since', 'missing_fields', 'missing_required',
        'first_reminded_at', 'second_reminded_at', 'deliveries', 'measure', 'measure_started_at',
        'ended_measures', 'resolved_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'stage' => SellerDataEscalationStage::class,
        'incomplete_since' => UtcDateTime::class,
        'missing_fields' => 'array',
        'missing_required' => 'boolean',
        'first_reminded_at' => UtcDateTime::class,
        'second_reminded_at' => UtcDateTime::class,
        'deliveries' => 'array',
        'measure' => SellerDataMeasure::class,
        'measure_started_at' => UtcDateTime::class,
        'ended_measures' => 'array',
        'resolved_at' => UtcDateTime::class,
        'merchant_erased_at' => UtcDateTime::class,
    ];

    /**
     * End the measure in force, keeping it on the record with the reason.
     *
     * Does nothing where no measure is in force, so a caller can end "whatever applies" without asking first.
     */
    public function endMeasure(CarbonInterface $at, string $reason): void
    {
        if (! $this->measure instanceof SellerDataMeasure) {
            return;
        }

        $this->ended_measures = [...($this->ended_measures ?? []), [
            'measure' => $this->measure->value,
            'started_at' => ($this->measure_started_at ?? $at)->toIso8601String(),
            'ended_at' => $at->toIso8601String(),
            'reason' => $reason,
        ]];
        $this->measure = null;
        $this->measure_started_at = null;
    }
}
