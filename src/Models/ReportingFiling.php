<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Override;
use Pushery\Billing\Casts\UtcDateTime;
use Pushery\Billing\Models\Concerns\AppendOnly;
use RuntimeException;

/**
 * One filing of one produced reporting record: what went out, when, by whom, and what it corrects.
 *
 * ## A filing is an act, an export is an artifact
 *
 * {@see ReportingExportRecord} says what was produced. This says what was SUBMITTED — and the two are far
 * apart in practice. A period is produced, compared against the previous run, discussed, and then filed
 * days later, or never, because the comparison showed something. Folding the moment of filing into the
 * export as a column would need that row to be writable after the fact, and its whole value is that it is
 * not.
 *
 * ## Before filing, after filing
 *
 * This row is what tells the two states apart. Before it exists, a period may be produced as often as
 * anybody likes and must come back byte-identical each time. Once it exists, the figures that went out are
 * fixed: a later correction, a master-data fix, a refund landing against last year — none of them reach
 * back. They produce a NEW export, which is filed as a correction naming this one.
 *
 * ## Immutable, like everything else in this chain
 *
 * A filing is a record of a statutory act. Editing it afterwards would let the moment, the person or the
 * corrected-filing reference be rewritten to fit a later story, which is precisely what a record of an act
 * must not allow. There is no withdrawal path either: a filing that went out cannot be un-sent, and a
 * mistake in what was filed is answered by a correction, not by tidying the row away.
 *
 * ## Why the sequence is stored rather than counted
 *
 * `correction_sequence` could be derived by counting rows, and derived it would enforce nothing. Stored and
 * NOT NULL, it carries the unique key that makes a second first filing of a period a database error rather
 * than a second first report — see the migration for why a partial index over the nullable reference could
 * not do the same job.
 *
 * @property int $id
 * @property int $export_id
 * @property int $period_year
 * @property string $currency
 * @property int $correction_sequence 0 for the first filing of the period, then 1, 2, 3 … per correction
 * @property ?int $corrects_filing_id null exactly when the sequence is 0
 * @property Carbon $filed_at
 * @property string $filed_by
 * @property-read ReportingExportRecord $export
 * @property-read ?ReportingFiling $corrects
 */
final class ReportingFiling extends Model
{
    use AppendOnly;

    protected $table = 'billing_reporting_filings';

    /** @var list<string> */
    protected $fillable = [
        'export_id', 'period_year', 'currency', 'correction_sequence',
        'corrects_filing_id', 'filed_at', 'filed_by',
    ];

    /** @var array<string,string> */
    protected $casts = [
        'export_id' => 'integer',
        'period_year' => 'integer',
        'correction_sequence' => 'integer',
        'corrects_filing_id' => 'integer',
        'filed_at' => UtcDateTime::class,
    ];

    /**
     * The record whose exact bytes this filing submitted.
     *
     * @return BelongsTo<ReportingExportRecord, $this>
     */
    public function export(): BelongsTo
    {
        return $this->belongsTo(ReportingExportRecord::class, 'export_id');
    }

    /**
     * The filing this one corrects, or null on a first filing.
     *
     * @return BelongsTo<self, $this>
     */
    public function corrects(): BelongsTo
    {
        return $this->belongsTo(self::class, 'corrects_filing_id');
    }

    /** Whether this is the period's first filing rather than a correction of an earlier one. */
    public function isFirstFiling(): bool
    {
        return $this->correction_sequence === 0;
    }

    #[Override]
    protected static function booted(): void
    {
        self::creating(static function (self $filing): void {
            $filing->filed_at ??= Carbon::now();

            // The sequence and the reference state the same thing, and a row where they disagree is the one
            // shape this table exists to forbid. A correction that names nothing IS a second first report,
            // whatever the sequence number on it says; a first filing that names a predecessor claims to
            // correct something while occupying the slot of the original.
            $names = $filing->corrects_filing_id !== null;

            if ($filing->isFirstFiling() && $names) {
                throw new RuntimeException(
                    'A first filing corrects nothing, so it cannot name a filing it corrects. File it as a '
                    .'correction — sequence 1 or higher — if that is what it is.'
                );
            }

            if (! $filing->isFirstFiling() && ! $names) {
                throw new RuntimeException(
                    'A correction must name the filing it corrects. A correction run without a reference to '
                    .'what it corrects is a second first report, and reporting a period twice is the '
                    .'direction that gets sanctioned.'
                );
            }
        });

    }

    #[Override]
    protected static function appendOnlyUpdateRefusal(array $columns): string
    {
        return 'A filing records a statutory act; it cannot be edited afterwards. What went out went out — '
            .'file a correction naming it, which is the only way a filed period ever changes.';
    }

    #[Override]
    protected static function appendOnlyDeleteRefusal(): string
    {
        return 'This row carries a statutory retention window; retention removes it on its schedule, '
            .'inside purging(). A caller does not delete it.';
    }
}
