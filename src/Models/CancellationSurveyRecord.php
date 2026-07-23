<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Pushery\Billing\Enums\CancellationReason;
use Pushery\Billing\ValueObjects\CancellationSurvey;

/**
 * A stored cancellation survey — the reason an owner gave when leaving, for churn analytics. It is the
 * persisted counterpart of the neutral {@see CancellationSurvey} DTO, keyed to the owner. Operational
 * data with no legal retention: it is purged with the owner, never kept as a financial record.
 *
 * @property int $id
 * @property string $owner_type
 * @property int $owner_id
 * @property CancellationReason $reason
 * @property ?string $detail
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
final class CancellationSurveyRecord extends Model
{
    protected $table = 'billing_cancellation_surveys';

    /** @var list<string> */
    protected $fillable = ['owner_type', 'owner_id', 'reason', 'detail'];

    /** @var array<string, string> */
    protected $casts = ['reason' => CancellationReason::class];

    /**
     * Persist an owner's cancellation survey from the neutral DTO — the one place the survey becomes a row,
     * so the mapping lives in one spot. Only ever called when the owner actually chose a reason.
     */
    public static function record(Model $owner, CancellationSurvey $survey): self
    {
        return self::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
            'reason' => $survey->reason,
            'detail' => $survey->detail,
        ]);
    }
}
