<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Override;
use Pushery\Billing\Models\Concerns\AppendOnly;

/**
 * One person's answer to one finding, for one period.
 *
 * ## The period is part of the key, and that is the whole design
 *
 * An acknowledgement says "I have looked at this and the filing may go ahead anyway". It is a statement
 * about a moment, not about a rule. Carried forward, it becomes a rule that was switched off with a
 * timestamp in front of it — the most expensive kind of dead check, because the report still lists it as
 * passing.
 *
 * So the same finding next year is a NEW finding, and somebody answers it again. That is not friction: the
 * answer may well be different, and the year in which it changed is precisely what nobody would notice.
 *
 * ## Why the reason is required rather than encouraged
 *
 * A finding waved through without one is indistinguishable from a finding nobody read. The reason is what a
 * later reader — an auditor, or the same operator in twelve months — uses to decide whether the judgement
 * still holds, and it is the only part of this row that cannot be reconstructed.
 *
 * ## Immutable once written
 *
 * An acknowledgement is a record of what somebody decided, when. Editing it afterwards would let the reason
 * be rewritten to fit an outcome, which is the one thing a record of a judgement must not allow. Withdraw
 * it — delete the row — and answer again if the judgement changed.
 *
 * @property int $id
 * @property int $period_year
 * @property string $currency
 * @property string $finding_key the finding's `rule|subject` identity, deliberately without its message
 * @property string $acknowledged_by
 * @property CarbonImmutable $acknowledged_at
 * @property string $reason
 */
final class ReportingFindingAcknowledgement extends Model
{
    use AppendOnly;

    protected $table = 'billing_reporting_acknowledgements';

    /** @var list<string> */
    protected $fillable = [
        'period_year', 'currency', 'finding_key', 'acknowledged_by', 'acknowledged_at', 'reason',
    ];

    /** @var array<string,string> */
    protected $casts = [
        'period_year' => 'integer',
        'acknowledged_at' => 'immutable_datetime',
    ];

    #[Override]
    protected static function booted(): void
    {
        self::creating(static function (self $acknowledgement): void {
            $acknowledgement->acknowledged_at ??= CarbonImmutable::now();
        });

    }

    #[Override]
    protected static function appendOnlyUpdateRefusal(array $columns): string
    {
        return 'An acknowledgement records what somebody decided and when; it cannot be edited '
            .'afterwards. Withdraw it and acknowledge again if the judgement changed.';
    }

    #[Override]
    protected static function appendOnlyDeleteRefusal(): string
    {
        return 'This row carries a statutory retention window; retention removes it on its schedule, '
            .'inside purging(). A caller does not delete it.';
    }
}
