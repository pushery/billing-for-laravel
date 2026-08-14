<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use InvalidArgumentException;
use Pushery\Billing\Enums\ChargeType;
use Pushery\Billing\Enums\RoundingResidual;

/**
 * What the platform keeps of a routed sale: a rate, a fixed amount, or both.
 *
 * A GROSS take, not a margin. Under the shipped connected-account type the provider's own processing fee is
 * paid by the PLATFORM — stated on the account as `controller.fees.payer`, measured on 2026-08-06 against
 * the pinned API version — so what the platform actually nets is this figure minus that fee. Nothing here
 * subtracts it, and nothing could: the package does not know a consumer's pricing with their provider, and
 * a guessed deduction would put every payout figure out by the size of the guess. See
 * {@see ChargeType} for the measurement and which setting moves the incidence.
 *
 * Both, because both are ordinary — the payment provider's own pricing is a percentage plus a fixed amount
 * per transaction, and a marketplace that could express only one of them would have to approximate the
 * other and explain the difference to somebody eventually.
 *
 * The split is computed as a WHOLE rather than as two independent roundings, and that is the rule that
 * keeps a cent from appearing out of nothing. Rounding each side separately on a net of 100.05 at 10% gives
 * a payout of 90.05 and a fee of 10.01 — 100.06 between them, a cent nobody paid. One side is computed and
 * the other is the difference, always.
 */
final readonly class PlatformFee
{
    /**
     * @param  int  $bps  the rate in basis points — 250 is 2.5%
     * @param  int  $flatMinor  a fixed amount per transaction, in the currency's minor units
     * @param  RoundingResidual  $residual  which side keeps the leftover minor unit of an uneven split
     */
    public function __construct(
        public int $bps = 0,
        public int $flatMinor = 0,
        public RoundingResidual $residual = RoundingResidual::ToPortion,
    ) {
        if ($bps < 0 || $bps > 10_000) {
            throw new InvalidArgumentException("A platform fee rate must be between 0 and 10000 bps; got {$bps}.");
        }

        if ($flatMinor < 0) {
            throw new InvalidArgumentException("A flat platform fee cannot be negative; got {$flatMinor}.");
        }
    }

    /** Whether this platform keeps anything at all. A package that ships no take rate is the neutral case. */
    public function isZero(): bool
    {
        return $this->bps === 0 && $this->flatMinor === 0;
    }

    /**
     * Split a payment into what the platform keeps and what the merchant is owed.
     *
     * The rate applies to the WHOLE amount and the flat part is added to it — not the other way round. The
     * difference is not cosmetic: charging the rate on what is left after a fixed amount makes the
     * effective rate depend on the transaction size, so the same stated terms would take a different share
     * of a small sale than of a large one. The order here is the one the commercial terms describe, and the
     * one every worked figure in the specification is computed with.
     *
     * The payout is the DIFFERENCE rather than a second calculation, so the two always sum back to the
     * payment. The rate's own share is taken with the bps primitive, which is where the disclosed rounding
     * direction lives; adding the flat amount afterwards cannot move it.
     *
     * @return array{Money, Money} [fee, merchantNet]
     */
    public function splitOf(Money $amount): array
    {
        if ($amount->isNegative()) {
            throw new InvalidArgumentException('A platform fee cannot be taken from a negative amount.');
        }

        // splitByBps(0) returns a zero portion cleanly, so there is no zero-rate case to special-case: the
        // primitive already answers it, and a branch that only repeats what it answers is a place for the
        // two paths to drift.
        $rated = $amount->splitByBps($this->bps, $this->residual)[0];

        // Capped at the payment. A fixed amount larger than a small sale would otherwise leave the merchant
        // owing money on something they just sold.
        $fee = new Money(min($rated->minorUnits + $this->flatMinor, $amount->minorUnits), $amount->currency);

        return [$fee, $amount->minus($fee)];
    }

    /** What the platform keeps of a payment. */
    public function of(Money $amount): Money
    {
        return $this->splitOf($amount)[0];
    }

    /** What the merchant is owed of a payment. */
    public function netOf(Money $amount): Money
    {
        return $this->splitOf($amount)[1];
    }
}
