<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Override;
use Pushery\Billing\Casts\UtcDateTime;
use Pushery\Billing\Models\Concerns\AppendOnly;

/**
 * One produced seller-reporting record: what was reported, when, and the exact bytes it was reported as.
 *
 * Immutable once written, and that is the whole value of it. The row exists to answer "which figures did we
 * actually report for that year", and an answer that can be edited afterwards answers nothing — it says
 * what somebody believes today, which is precisely what a later dispute is about.
 *
 * @property int $id
 * @property int $period_year
 * @property string $currency
 * @property string $format
 * @property string $format_version the version of that format these bytes were built to
 * @property Carbon $generated_at
 * @property int $seller_count
 * @property string $checksum sha-256 over the exact bytes, which is what a re-run is compared against
 * @property string $contents
 * @property ?string $written_to where a copy was placed, or null when configuration points nowhere
 * @property-read ?ReportingFiling $filing
 */
final class ReportingExportRecord extends Model
{
    use AppendOnly;

    protected $table = 'billing_reporting_exports';

    /** @var list<string> */
    protected $fillable = [
        'period_year', 'currency', 'format', 'format_version', 'generated_at',
        'seller_count', 'checksum', 'contents', 'written_to',
    ];

    /** @var array<string,string> */
    protected $casts = [
        'period_year' => 'integer',
        'seller_count' => 'integer',
        'generated_at' => UtcDateTime::class,
    ];

    /**
     * The filing that submitted these bytes, or null while the record has only been produced.
     *
     * The state lives in a row of its own rather than a column here, and that is what lets this row stay
     * immutable in every column: a `filed_at` here would have to be writable after the fact, which is the
     * one property a record of what was produced must not have.
     *
     * @return HasOne<ReportingFiling, $this>
     */
    public function filing(): HasOne
    {
        return $this->hasOne(ReportingFiling::class, 'export_id');
    }

    /**
     * Whether these exact bytes went out.
     *
     * The line between "may be produced again, and must come back identical" and "is settled — a change is
     * a correction naming this one".
     */
    public function wasFiled(): bool
    {
        return $this->filing()->exists();
    }

    #[Override]
    protected static function appendOnlyUpdateRefusal(array $columns): string
    {
        return 'A produced reporting record is what was reported; it cannot be edited afterwards. Produce '
            .'the period again — a second run is a second row, and the two being comparable is the point.';
    }

    #[Override]
    protected static function appendOnlyDeleteRefusal(): string
    {
        return 'This row carries a statutory retention window; retention removes it on its schedule, '
            .'inside purging(). A caller does not delete it.';
    }
}
