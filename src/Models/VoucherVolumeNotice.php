<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Pushery\Billing\Casts\UtcDateTime;
use Pushery\Billing\Enums\VoucherVolumeLevel;

/**
 * A record that one voucher-volume level, for one currency, in one year, has been announced.
 *
 * The figure is kept beside the marker rather than only the fact. "We told them" answers less than an
 * auditor asks: what an operator wants back is what the number was on the day they were told, and
 * recomputing it later gives a different answer, because the window has moved on since.
 *
 * @property int $id
 * @property string $currency
 * @property VoucherVolumeLevel $level
 * @property int $announced_for_year
 * @property int $volume_minor
 * @property Carbon $announced_at
 */
final class VoucherVolumeNotice extends Model
{
    protected $table = 'billing_voucher_volume_notices';

    /** @var list<string> */
    protected $fillable = ['currency', 'level', 'announced_for_year', 'volume_minor', 'announced_at'];

    /** @var array<string,string> */
    protected $casts = [
        'level' => VoucherVolumeLevel::class,
        'announced_for_year' => 'integer',
        'volume_minor' => 'integer',
        'announced_at' => UtcDateTime::class,
    ];
}
