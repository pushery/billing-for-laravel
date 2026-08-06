<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Override;
use Pushery\Billing\Enums\ExchangeRateBasis;
use Pushery\Billing\Enums\ExchangeRateLayer;
use Pushery\Billing\Tax\FrozenExchangeRate;
use RuntimeException;

/**
 * One conversion of one document, as it was made — and it never changes afterwards.
 *
 * @property int $invoice_id
 * @property ExchangeRateLayer $layer
 * @property string $from_currency
 * @property string $to_currency
 * @property int $rate_scaled
 * @property Carbon $rate_date
 * @property ExchangeRateBasis $basis
 * @property string $source
 */
final class InvoiceExchangeRate extends Model
{
    protected $table = 'billing_invoice_exchange_rates';

    /** @var list<string> */
    protected $fillable = [
        'invoice_id', 'layer', 'from_currency', 'to_currency', 'rate_scaled', 'rate_date', 'basis', 'source',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'layer' => ExchangeRateLayer::class,
        'basis' => ExchangeRateBasis::class,
        'rate_scaled' => 'integer',
        // A plain date, like the rate store: a published rate belongs to a calendar day in the publisher's
        // reckoning, and reading it back through a timezone is how it stops belonging to that day.
        'rate_date' => 'date',
    ];

    #[Override]
    protected static function booted(): void
    {
        // Refused as a whole row rather than field by field, unlike the invoice's own frozen list. There is
        // no column here that may legitimately change after the document was issued: a rate, its date, its
        // rule and its publisher are one statement about one moment. Naming protected fields would invite
        // the question of which ones are not.
        self::updating(static function (): never {
            throw new RuntimeException(
                'A frozen exchange rate cannot change after the document was issued. A correction reads what '
                .'was recorded here; re-deriving the rate would reverse an amount nobody ever declared.'
            );
        });
    }

    /** The value object this row is a persisted copy of. */
    public function frozen(): FrozenExchangeRate
    {
        return new FrozenExchangeRate(
            $this->from_currency,
            $this->to_currency,
            $this->rate_scaled,
            CarbonImmutable::parse($this->rate_date->toDateString()),
            $this->source,
            $this->basis,
        );
    }
}
