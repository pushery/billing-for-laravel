<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Pushery\Billing\Enums\VoucherEvent;
use Pushery\Billing\Enums\VoucherInstrumentType;
use Pushery\Billing\Exceptions\VoucherNotPermitted;
use Pushery\Billing\Models\Voucher;
use Pushery\Billing\Models\VoucherMovementRecord;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\VoucherMovement;

/**
 * Vouchers: what can happen to one, and everything that deliberately cannot.
 *
 * ## Four properties, none of them configurable
 *
 * Redeemable only here · never paid out · never topped up · never handed to somebody else. Together they are
 * what keeps this outside regulated money, and they are held by there being no method for any of them rather
 * than by a setting that says no. A switch that turned one off would look like any other setting and would
 * change what the instrument legally IS — so there is no switch, and a guard test asserts the absences so
 * they stay properties instead of accidents.
 *
 * Redemption therefore takes a sale to pay towards, and the remaining value only ever goes down.
 *
 * ## The type is frozen when the voucher is sold
 *
 * It decides when the tax falls — at issue, or when the voucher is finally used — and a supply already made
 * cannot be re-decided by a configuration change months later. The DEFAULT is a jurisdiction's question; the
 * frozen value is the voucher's own.
 *
 * ## Expiry is income, not turnover
 *
 * What is left when a voucher runs out was paid for a supply that never happened. Booking it as turnover
 * would claim a sale nobody made, and carry tax with it.
 */
final readonly class VoucherLedger
{
    public function __construct(private Repository $config) {}

    /** Whether this installation offers vouchers at all. Off unless somebody turned it on. */
    public function enabled(): bool
    {
        return (bool) $this->config->get('billing.marketplace.vouchers.enabled', false);
    }

    /**
     * Sell a voucher.
     *
     * The type is read from configuration ONCE, here, and stored. Everything downstream reads the stored
     * value — a voucher issued while the platform sold into many countries stays what it was, whatever the
     * platform does later.
     */
    public function issue(
        string $code,
        Money $faceValue,
        CarbonInterface $issuedAt,
        ?Model $owner = null,
    ): Voucher {
        $this->assertEnabled();

        $expiresAfter = $this->config->get('billing.marketplace.vouchers.expire_after_days');

        $voucher = Voucher::query()->create([
            'code' => $code,
            'owner_type' => $owner?->getMorphClass(),
            'owner_id' => $this->ownerKey($owner),
            'currency' => $faceValue->currency,
            'face_value_minor' => $faceValue->minorUnits,
            'remaining_minor' => $faceValue->minorUnits,
            'instrument_type' => $this->defaultInstrumentType(),
            'issued_at' => $issuedAt,
            'expires_at' => is_int($expiresAfter) ? $issuedAt->copy()->addDays($expiresAfter) : null,
        ]);

        // The FIRST producer this event has ever had. `VoucherEvent::Issued` was consumed by the exporter
        // and produced by nothing, so the liability booking a voucher creates on the day it is sold could
        // not occur — while the turnover at redemption would eventually appear with no counter-entry.
        $this->record(new VoucherMovement(VoucherEvent::Issued, $faceValue, $code, $issuedAt));

        return $voucher;
    }

    /**
     * Spend part or all of a voucher against a sale.
     *
     * The sale is required. Without one this would be a way of getting money back out, which is exactly the
     * property the instrument does not have.
     */
    public function redeem(Voucher $voucher, Money $amount, Money $saleGross, CarbonInterface $at): VoucherMovement
    {
        $this->assertEnabled();

        if ($voucher->expired_at !== null) {
            throw VoucherNotPermitted::alreadyExpired($voucher->code);
        }

        if ($amount->minorUnits > $voucher->remaining_minor) {
            throw VoucherNotPermitted::overRemainingValue($voucher->code, $amount->minorUnits, $voucher->remaining_minor);
        }

        $voucher->remaining_minor -= $amount->minorUnits;
        $voucher->save();

        return $this->record(
            new VoucherMovement(VoucherEvent::Redeemed, $amount, $voucher->code, $at, saleGross: $saleGross),
        );
    }

    /**
     * Close out a voucher nobody used in time.
     *
     * What is left becomes income — money kept for a supply that was never made. Returns null where there
     * was nothing left, because an expiry with no value is not an event the books need.
     */
    public function expire(Voucher $voucher, CarbonInterface $at): ?VoucherMovement
    {
        $remaining = $voucher->remaining_minor;

        $voucher->expired_at = Carbon::instance($at->toDateTime());
        $voucher->remaining_minor = 0;
        $voucher->save();

        // Null where nothing was left, unchanged: an expiry with no value is not an event the books need,
        // and persisting a zero would put an income booking on a month where no income arose.
        return $remaining > 0
            ? $this->record(new VoucherMovement(VoucherEvent::Expired, Money::of($remaining, $voucher->currency), $voucher->code, $at))
            : null;
    }

    /**
     * Write a movement down, and hand back the same value the caller was always given.
     *
     * The persistence sits BESIDE the return value rather than replacing it: every existing caller keeps
     * working unchanged, and the exporter — which reads the value object — gets the same shape whether it
     * came from a method call or from the table. What changes is only that the movement now outlives the
     * method that produced it, which is exactly what it could not do before.
     *
     * Written here rather than in an observer. These three methods are the only place a movement comes into
     * existence at all, and an observer over a RETURN VALUE (rather than over a model event) would be a
     * construction with no precedent anywhere in this package.
     */
    private function record(VoucherMovement $movement): VoucherMovement
    {
        VoucherMovementRecord::query()->create([
            'event' => $movement->event,
            'reference' => $movement->reference,
            'amount_minor' => $movement->amount->minorUnits,
            'currency' => $movement->amount->currency,
            'sale_gross_minor' => $movement->saleGross?->minorUnits,
            'occurred_on' => $movement->occurredOn,
        ]);

        return $movement;
    }

    /**
     * What was sold in vouchers over a window — the figure a reporting threshold is measured against.
     *
     * Face value at issue, not what has been spent since: the threshold asks how much money went into the
     * instrument, and value still sitting unspent went in just the same.
     */
    public function issuedVolumeSince(CarbonInterface $since, string $currency): Money
    {
        $total = Voucher::query()
            ->where('currency', $currency)
            ->where('issued_at', '>=', $since)
            ->sum('face_value_minor');

        return Money::of((int) $total, $currency);
    }

    private function defaultInstrumentType(): VoucherInstrumentType
    {
        $configured = $this->config->get('billing.marketplace.vouchers.instrument_type');

        return VoucherInstrumentType::tryFrom(is_string($configured) ? $configured : '')
            ?? VoucherInstrumentType::MultiPurpose;
    }

    private function ownerKey(?Model $owner): ?string
    {
        $key = $owner?->getKey();

        return is_scalar($key) ? (string) $key : null;
    }

    private function assertEnabled(): void
    {
        if (! $this->enabled()) {
            throw VoucherNotPermitted::featureDisabled();
        }
    }
}
