<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Override;
use Pushery\Billing\Casts\UtcDateTime;
use Pushery\Billing\Models\Concerns\AppendOnly;

/**
 * A return file that was produced, as the evidence it is.
 *
 * @property int $id
 * @property int $year
 * @property int $quarter
 * @property string $period_label
 * @property string $currency
 * @property Carbon $generated_at
 * @property int $line_count
 * @property int $net_minor
 * @property int $tax_minor
 * @property string $checksum
 * @property string $contents
 * @property ?string $written_to
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
final class TaxReturnExportRecord extends Model
{
    use AppendOnly;

    protected $table = 'billing_tax_return_exports';

    /** @var list<string> */
    protected $fillable = [
        'year', 'quarter', 'period_label', 'currency', 'generated_at',
        'line_count', 'net_minor', 'tax_minor', 'checksum', 'contents', 'written_to',
    ];

    /** @var array<string,string> */
    protected $casts = [
        'generated_at' => UtcDateTime::class,
        'line_count' => 'integer',
        'net_minor' => 'integer',
        'tax_minor' => 'integer',
    ];

    /**
     * Run a retention purge, the one context in which these rows may be deleted.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */

    /**
     * Where the file was put may be corrected — a disk gets renamed, a file gets archived elsewhere, and
     * none of that changes what was filed. The figures and the bytes cannot move.
     *
     * @return list<string>
     */
    protected static function appendOnlyMutableColumns(): array
    {
        return ['written_to', 'updated_at'];
    }

    #[Override]
    protected static function appendOnlyUpdateRefusal(array $columns): string
    {
        return 'A produced return is the record of what was filed and cannot be edited afterwards; '
            .'attempted to change '.implode(', ', $columns).'. Produce the period again — a second export '
            .'is a second row, and the fact worth keeping is whether the two agree.';
    }

    #[Override]
    protected static function appendOnlyDeleteRefusal(): string
    {
        return 'A produced return cannot be deleted; it is the only evidence of which figures were filed. '
            .'Retention removes it on its schedule, inside purging() — a caller does not.';
    }
}
