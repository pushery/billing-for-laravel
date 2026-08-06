<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Override;
use Pushery\Billing\Casts\UtcDateTime;
use RuntimeException;

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
    /**
     * Whether a deliberate purge (retention pruning) is in progress.
     *
     * Same shape as the audit ledger's, and for the same reason: a produced return is only evidence of what
     * was filed if it cannot quietly leave. The one authorized way out is a clock — never an ad-hoc delete.
     */
    private static bool $purging = false;

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
    public static function purging(callable $callback): mixed
    {
        self::$purging = true;

        try {
            return $callback();
        } finally {
            self::$purging = false;
        }
    }

    #[Override]
    protected static function booted(): void
    {
        self::updating(function (self $record): void {
            // Where the file was put may be corrected — a disk gets renamed, a file gets archived elsewhere,
            // and none of that changes what was filed. The figures and the bytes cannot move.
            $touched = array_keys($record->getDirty());
            $allowed = ['written_to', 'updated_at'];

            if (array_diff($touched, $allowed) !== []) {
                throw new RuntimeException(
                    'A produced return is the record of what was filed and cannot be edited afterwards; '
                    .'attempted to change '.implode(', ', array_diff($touched, $allowed)).'. Produce the '
                    .'period again — a second export is a second row, and the fact worth keeping is whether '
                    .'the two agree.'
                );
            }
        });

        self::deleting(static function (): bool {
            if (self::$purging) {
                return true;
            }

            throw new RuntimeException(
                'A produced return cannot be deleted; it is the only evidence of which figures were filed. '
                .'Retention removes it on its schedule, inside purging() — a caller does not.'
            );
        });
    }
}
