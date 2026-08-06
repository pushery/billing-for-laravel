<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Pushery\Billing\Enums\ExchangeRateBasis;

/**
 * One published rate, as its publisher stated it.
 *
 * A row here is a quotation, not a calculation: nothing in this package derives one rate from another. The
 * ministry's monthly average is imported from the ministry even though it is computed from central-bank
 * dailies, because the law asks for the rate that was officially announced, and an average we computed
 * ourselves would be a plausible number nobody published.
 *
 * @property string $from_currency
 * @property string $to_currency
 * @property Carbon $rate_date
 * @property ExchangeRateBasis $basis
 * @property int $rate_scaled
 * @property string $source
 * @property ?Carbon $created_at
 */
final class ExchangeRateRecord extends Model
{
    protected $table = 'billing_exchange_rates';

    /** @var list<string> */
    protected $fillable = ['from_currency', 'to_currency', 'rate_date', 'basis', 'rate_scaled', 'source'];

    /** @var array<string, string> */
    protected $casts = [
        // A plain `date`, deliberately, where the rest of this package uses UtcDateTime. There is no instant
        // here to move across a midnight: a published rate belongs to a calendar day in the publisher's own
        // reckoning, and reading it back through a timezone is how it would stop belonging to that day.
        'rate_date' => 'date',
        'basis' => ExchangeRateBasis::class,
        'rate_scaled' => 'integer',
    ];
}
