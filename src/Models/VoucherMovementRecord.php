<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Pushery\Billing\Casts\UtcDateTime;
use Pushery\Billing\Enums\VoucherEvent;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\VoucherMovement;

/**
 * One voucher movement, as it happened.
 *
 * Named `…Record` because {@see VoucherMovement} already holds the name and is the value the booking logic
 * reads. The two are deliberately separate: the value object is what the exporter consumes and what the
 * ledger returns, and this is the row that lets it survive the end of a method call — which is precisely
 * what it could not do before, and why three configured bookings could never occur.
 *
 * @property VoucherEvent $event
 * @property string $reference
 * @property int $amount_minor
 * @property string $currency
 * @property ?int $sale_gross_minor
 * @property Carbon $occurred_on
 */
final class VoucherMovementRecord extends Model
{
    protected $table = 'billing_voucher_movements';

    protected $fillable = ['event', 'reference', 'amount_minor', 'currency', 'sale_gross_minor', 'occurred_on'];

    protected $casts = [
        'event' => VoucherEvent::class,
        'amount_minor' => 'integer',
        'sale_gross_minor' => 'integer',
        'occurred_on' => UtcDateTime::class,
    ];

    /** The value the booking logic reads, rebuilt from the row. */
    public function toMovement(): VoucherMovement
    {
        return new VoucherMovement(
            $this->event,
            Money::of($this->amount_minor, $this->currency),
            $this->reference,
            $this->occurred_on,
            saleGross: $this->sale_gross_minor === null ? null : Money::of($this->sale_gross_minor, $this->currency),
        );
    }
}
