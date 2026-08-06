<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Carbon\CarbonInterface;
use InvalidArgumentException;
use Pushery\Billing\Enums\VoucherEvent;

/**
 * One thing that happened to a multi-purpose voucher, in the form the books need it.
 *
 * A voucher whose tax treatment is not fixed at issue — because neither the place of supply nor the rate is
 * known yet — is not revenue when it is sold. It is money held against a promise. The three events therefore
 * book three different things, and getting that wrong is not a rounding matter: taxing it at issue taxes a
 * supply that has not happened, and taxing it again at redemption taxes it twice.
 *
 * The redemption carries the part of the sale the voucher paid for, alongside the full gross. Those are not
 * the same number and must not be netted: the voucher reduces what the buyer PAYS, never what was sold.
 */
final readonly class VoucherMovement
{
    public function __construct(
        public VoucherEvent $event,
        public Money $amount,
        public string $reference,
        public CarbonInterface $occurredOn,
        /** On a redemption: what the whole sale came to, of which {@see $amount} was paid with the voucher. */
        public ?Money $saleGross = null,
    ) {
        if ($event !== VoucherEvent::Redeemed) {
            return;
        }

        if (! $saleGross instanceof Money) {
            throw new InvalidArgumentException(
                'A redemption states the sale it paid towards. Without it the booking would turn the voucher '
                .'into the whole turnover, which understates the sale by whatever the buyer paid on top.'
            );
        }

        if ($saleGross->minorUnits < $amount->minorUnits) {
            throw new InvalidArgumentException(
                'A voucher cannot pay more than the sale it is redeemed against; that would book negative '
                .'money in transit and leave the difference as revenue nobody received.'
            );
        }
    }

    /** What the buyer paid on top of the voucher — zero when the voucher covered the whole sale. */
    public function paidOnTop(): Money
    {
        return $this->saleGross instanceof Money ? $this->saleGross->minus($this->amount) : $this->amount;
    }
}
