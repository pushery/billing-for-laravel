<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Pushery\Billing\Casts\UtcDateTime;
use Pushery\Billing\Enums\ChargeType;
use Pushery\Billing\Enums\RoundingResidual;
use Pushery\Billing\Enums\SettlementState;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\PlatformFee;

/**
 * A payment that was routed to a merchant, and what has since been taken back off it.
 *
 * @property int $id
 * @property string $merchant_type
 * @property int $merchant_id
 * @property string $provider
 * @property string $charge_reference
 * @property ?string $transfer_reference
 * @property ?ChargeType $charge_type
 * @property int $gross_minor
 * @property int $fee_minor
 * @property ?int $fee_bps
 * @property ?int $fee_flat_minor
 * @property ?RoundingResidual $fee_residual
 * @property ?int $commission_tax_bps
 * @property int $net_minor
 * @property string $currency
 * @property SettlementState $settlement_state
 * @property ?Carbon $settled_at
 * @property int $refunded_minor
 * @property int $transfer_reversed_minor
 * @property int $fee_refunded_minor
 * @property ?Carbon $merchant_erased_at
 */
final class MerchantCharge extends Model
{
    protected $table = 'billing_merchant_charges';

    /** @var list<string> */
    protected $fillable = [
        'merchant_type', 'merchant_id', 'provider', 'charge_reference', 'transfer_reference', 'charge_type',
        'gross_minor', 'fee_minor', 'fee_bps', 'fee_flat_minor', 'fee_residual', 'commission_tax_bps', 'net_minor', 'currency', 'settlement_state', 'settled_at',
        'refunded_minor', 'transfer_reversed_minor', 'fee_refunded_minor', 'merchant_erased_at',
    ];

    /**
     * The same defaults the schema carries, so a row that was just created reads like one that was read back.
     *
     * Held against the migration by ModelSchemaDefaultsTest.
     *
     * @var array<string, int|string>
     */
    protected $attributes = [
        'settlement_state' => 'pending',
        'refunded_minor' => 0,
        'transfer_reversed_minor' => 0,
        'fee_refunded_minor' => 0,
    ];

    /** @var array<string,string> */
    protected $casts = [
        'gross_minor' => 'integer',
        'fee_minor' => 'integer',
        // Cast, so the direction reads back as the enum it was written as -- a raw string here would compare
        // unequal to every RoundingResidual case and quietly fall through to the configured fallback.
        'fee_residual' => RoundingResidual::class,
        'net_minor' => 'integer',
        'refunded_minor' => 'integer',
        'transfer_reversed_minor' => 'integer',
        'fee_refunded_minor' => 'integer',
        'settlement_state' => SettlementState::class,
        'charge_type' => ChargeType::class,
        'settled_at' => UtcDateTime::class,
        'merchant_erased_at' => UtcDateTime::class,
    ];

    /** @return MorphTo<Model, $this> */
    public function merchant(): MorphTo
    {
        return $this->morphTo();
    }

    /** What the buyer paid. */
    public function gross(): Money
    {
        return new Money($this->gross_minor, $this->currency);
    }

    /** What the platform kept. */
    public function fee(): Money
    {
        return new Money($this->fee_minor, $this->currency);
    }

    /** What the merchant was routed. */
    public function net(): Money
    {
        return new Money($this->net_minor, $this->currency);
    }

    /**
     * How much of the buyer's payment can still be refunded.
     *
     * Read from the gross, because that is what the buyer paid — not from the net, which is only the
     * merchant's share of it.
     */
    public function refundableMinor(): int
    {
        return max(0, $this->gross_minor - $this->refunded_minor);
    }

    /**
     * How much of the merchant's share can still be clawed back.
     *
     * A separate ceiling from the refundable amount, and deliberately so: with the platform keeping its
     * commission on a refund, the two run at different speeds forever after.
     */
    public function reversibleMinor(): int
    {
        return max(0, $this->net_minor - $this->transfer_reversed_minor);
    }

    /**
     * The amount the commission is taken on, for a payment of this sale's shape.
     *
     * The take rate is a NET rate — it applies to the transaction's net, not to what the buyer paid. On
     * 119.00 at 19% with a 10% rate that is 10.00 rather than 11.90, and the 1.90 between them is the
     * platform's commission on the buyer's tax, which was never the platform's money to keep.
     *
     * Called with the WHOLE payment to reconstruct the original split, and with what remains of it to
     * recompute a partial clawback — which is why the rate is frozen rather than the base amount: a base
     * amount cannot answer for a remainder, and scaling one would be a second calculation of the same fact.
     *
     * A row with no rate answers with the payment itself. That is not a fallback: it is what those rows
     * actually did, and reading them any other way would claw back against a base their money never moved
     * on. So the honest reading of an old row is the old basis, and it lives here rather than at each of
     * the three call sites that would otherwise each decide it.
     */
    public function commissionBase(Money $payment): Money
    {
        return $this->commission_tax_bps === null
            ? $payment
            : $payment->baseFromMarkup($this->commission_tax_bps)[0];
    }

    /**
     * What this sale was worth to the merchant BEFORE their own tax — the section-19 basis.
     *
     * Three different numbers come off one routed sale and only one of them belongs here. On 119.00 at 19%
     * with a 10% rate: the buyer paid 119.00, 109.00 reached the merchant, and 90.00 is what the supply was
     * worth. The threshold that decides whether a creator is still a small business is measured on the last
     * of the three, and the other two are about a fifth too high.
     *
     * Computed from the commission base rather than from `net_minor`, which is the merchant's whole receipt
     * and carries the buyer's tax with it — the tax being theirs to remit is exactly why it reaches them,
     * and exactly why it is not turnover of theirs.
     *
     * A row with no frozen rate cannot separate the two and answers with what it has. That over-counts, and
     * saying so is better than a rate invented for it: the alternative is guessing which tax a historical
     * sale carried, on a figure that decides a legal status.
     */
    public function payoutNet(): Money
    {
        return $this->commissionBase($this->gross())->minus($this->fee());
    }

    /**
     * The buyer's tax contained in a portion of this sale.
     *
     * Taken as a portion rather than as a whole because a reversal needs it twice — on the sale before the
     * refund and on what is left after — and the difference between those two is the tax that came back.
     * The same rate answers both, which is the reason the rate is what gets frozen.
     */
    public function taxWithin(Money $portion): Money
    {
        return $portion->minus($this->commissionBase($portion));
    }

    /**
     * The commission terms this sale was made under, or null when they were never recorded.
     *
     * Null is a real answer and the caller has to handle it. The alternative — reading today's configuration
     * — would claw back an old sale at a new rate and produce a figure that looks entirely plausible, which
     * is precisely why the terms are stored rather than derived. A row written before the columns existed
     * genuinely does not know, and saying so is the only honest thing left.
     *
     * The rounding residual is NOT part of what is frozen: it decides who absorbs a sub-cent remainder on a
     * split that has already happened, and re-splitting a completed sale is not something this reconstruction
     * does. It is used to recompute what a SMALLER sale would have paid out, where the residual rule of the
     * day applies — and that rule is READ, which it used to only claim to be. The third argument was left
     * off, so the constructor's default decided it, and an installation that hands the odd minor unit to the
     * creator reconstructed every uneven sale a minor unit off the one it was correcting. Nothing was red:
     * the arms above this one all divide exactly, and on an even split all three directions agree.
     */
    public function frozenFee(): ?PlatformFee
    {
        if ($this->fee_bps === null || $this->fee_flat_minor === null) {
            return null;
        }

        // The direction this sale was PRICED under, not the one configured today. `configuredResidual()` is
        // reached only by a row written before the column existed — see its docblock for why that fallback
        // is a guess and still the right one.
        return new PlatformFee($this->fee_bps, $this->fee_flat_minor, $this->fee_residual ?? $this->configuredResidual());
    }

    /**
     * What this installation does with the odd minor unit today.
     *
     * Deliberately identical to `RoutedRefundCorrector::configuredResidual()`, down to the fallback: the
     * money side and the document side reconstruct the SAME sale, so the one place they must not differ is
     * exactly this one. A value neither side can honor lands on `ToPortion` rather than throwing, because
     * this is a reconstruction of something that already happened — refusing to answer would make an old
     * charge unreadable over a setting that has nothing to do with it. The resolver that PRICES a sale does
     * throw, and that asymmetry is the point: a sale not yet made can still be stopped.
     */
    private function configuredResidual(): RoundingResidual
    {
        return RoundingResidual::fromConfigured(Config::get('billing.marketplace.fee.rounding'))
            ?? RoundingResidual::ToPortion;
    }
}
