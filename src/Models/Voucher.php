<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Pushery\Billing\Casts\UtcDateTime;
use Pushery\Billing\Enums\VoucherInstrumentType;

/**
 * One voucher and what is left of it.
 *
 * ## Who writes this row
 *
 * The PACKAGE does, through `VoucherLedger` -- issuing, redeeming and expiring are all money movements and
 * all belong to it. `expired_at` is set by property assignment rather than in a create payload, which is
 * worth knowing for any scan that looks for writers: the array form is not the only one.
 *
 * @property int $id
 * @property string $code
 * @property ?string $owner_type
 * @property ?string $owner_id
 * @property string $currency
 * @property int $face_value_minor
 * @property int $remaining_minor
 * @property VoucherInstrumentType $instrument_type
 * @property Carbon $issued_at
 * @property ?Carbon $expires_at
 * @property ?Carbon $expired_at
 * @property ?Carbon $owner_erased_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
final class Voucher extends Model
{
    protected $table = 'billing_vouchers';

    /** @var list<string> */
    protected $fillable = [
        'code', 'owner_type', 'owner_id', 'currency', 'face_value_minor', 'remaining_minor',
        'instrument_type', 'issued_at', 'expires_at', 'expired_at', 'owner_erased_at',
    ];

    /** @var array<string,string> */
    protected $casts = [
        'face_value_minor' => 'integer',
        'remaining_minor' => 'integer',
        'instrument_type' => VoucherInstrumentType::class,
        'issued_at' => UtcDateTime::class,
        'expires_at' => UtcDateTime::class,
        'expired_at' => UtcDateTime::class,
        'owner_erased_at' => UtcDateTime::class,
    ];

    /** Whether anything is left to spend. */
    public function spent(): bool
    {
        return $this->remaining_minor <= 0;
    }
}
