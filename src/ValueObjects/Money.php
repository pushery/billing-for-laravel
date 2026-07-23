<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use InvalidArgumentException;
use Pushery\Billing\Enums\RoundingResidual;
use Pushery\Billing\Exceptions\CurrencyMismatch;

/**
 * A monetary amount as integer minor units plus an ISO-4217 currency, normalized across every
 * payment driver. Some providers speak integer minor units, others a decimal-string amount plus
 * currency — Money is the single representation that crosses the contract boundary, so a raw int or
 * string never does. All arithmetic is integer/string based; floats are never used.
 */
final readonly class Money
{
    /**
     * Currencies whose minor-unit exponent differs from the default of 2. Everything not listed
     * uses two decimal places.
     *
     * @var array<string,int>
     */
    private const array EXPONENTS = [
        // Zero-decimal currencies (the payment providers treat these as having no minor unit).
        'JPY' => 0, 'KRW' => 0, 'VND' => 0, 'CLP' => 0, 'ISK' => 0, 'XOF' => 0, 'XAF' => 0,
        'BIF' => 0, 'DJF' => 0, 'GNF' => 0, 'KMF' => 0, 'MGA' => 0, 'PYG' => 0, 'RWF' => 0,
        'UGX' => 0, 'VUV' => 0, 'XPF' => 0,
        // Three-decimal currencies.
        'BHD' => 3, 'KWD' => 3, 'OMR' => 3, 'TND' => 3, 'JOD' => 3, 'IQD' => 3, 'LYD' => 3,
    ];

    public function __construct(
        public int $minorUnits,
        public string $currency,
    ) {
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException("Invalid ISO-4217 currency code: '{$currency}'.");
        }
    }

    public static function of(int $minorUnits, string $currency): self
    {
        return new self($minorUnits, $currency);
    }

    public static function zero(string $currency): self
    {
        return new self(0, $currency);
    }

    /**
     * Build from a decimal-string amount (the shape some providers use), e.g. "10.00" EUR → 1000
     * minor. The fractional precision must not exceed the currency's minor-unit exponent.
     */
    public static function fromDecimal(string $amount, string $currency): self
    {
        if (! preg_match('/^(-)?(\d+)(?:\.(\d+))?$/', $amount, $m)) {
            throw new InvalidArgumentException("Invalid decimal amount: '{$amount}'.");
        }

        $exponent = self::exponentFor($currency);
        $fraction = $m[3] ?? '';

        if (strlen($fraction) > $exponent) {
            throw new InvalidArgumentException(
                "Amount '{$amount}' has more precision than {$currency} allows ({$exponent} decimals)."
            );
        }

        $fraction = str_pad($fraction, $exponent, '0');
        $minor = (int) ($m[2].$fraction);

        return new self($m[1] === '-' ? -$minor : $minor, $currency);
    }

    /** The minor-unit exponent (decimal places) for a currency. */
    public static function exponentFor(string $currency): int
    {
        return self::EXPONENTS[$currency] ?? 2;
    }

    /** Render as a decimal string, e.g. 1000 EUR minor → "10.00", 500 JPY minor → "500". */
    public function toDecimal(): string
    {
        $exponent = self::exponentFor($this->currency);

        if ($exponent === 0) {
            return (string) $this->minorUnits;
        }

        $sign = $this->minorUnits < 0 ? '-' : '';
        $digits = str_pad((string) abs($this->minorUnits), $exponent + 1, '0', STR_PAD_LEFT);
        $integer = substr($digits, 0, -$exponent);
        $fraction = substr($digits, -$exponent);

        return "{$sign}{$integer}.{$fraction}";
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorUnits + $other->minorUnits, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorUnits - $other->minorUnits, $this->currency);
    }

    public function multipliedBy(int $factor): self
    {
        return new self($this->minorUnits * $factor, $this->currency);
    }

    public function negated(): self
    {
        return new self(-$this->minorUnits, $this->currency);
    }

    /** The magnitude, sign stripped — for formats that carry direction separately (a DATEV
     *  Soll/Haben marker, an EN 16931 credit-note type code) and reject a signed amount. */
    public function absolute(): self
    {
        return new self(abs($this->minorUnits), $this->currency);
    }

    /**
     * Split the amount across the given integer ratios with no lost or created minor units — the
     * rounding remainder is distributed one unit at a time to the earliest buckets (Fowler's
     * allocate). Used for credit-balance proration.
     *
     * @return list<self>
     */
    public function allocate(int ...$ratios): array
    {
        if ($ratios === []) {
            throw new InvalidArgumentException('Cannot allocate Money across zero ratios.');
        }

        $total = array_sum($ratios);

        if ($total <= 0) {
            throw new InvalidArgumentException('Allocation ratios must sum to a positive value.');
        }

        $remainder = $this->minorUnits;
        $shares = [];

        foreach ($ratios as $ratio) {
            $share = intdiv($this->minorUnits * $ratio, $total);
            $shares[] = $share;
            $remainder -= $share;
        }

        // Distribute the rounding remainder one minor unit at a time to the earliest buckets. The
        // step follows the remainder's sign so a negative amount (e.g. a refund) allocates exactly.
        $step = $remainder <=> 0;
        $count = count($shares);

        for ($i = 0; $remainder !== 0; $i = ($i + 1) % $count, $remainder -= $step) {
            $shares[$i] += $step;
        }

        return array_map(fn (int $s): self => new self($s, $this->currency), array_values($shares));
    }

    /**
     * Split into a bps portion and the remainder, summing EXACTLY to the original.
     *
     * A percentage of an integer amount rarely divides evenly, and the rule that keeps a cent from
     * appearing or vanishing is that one side is computed and the OTHER is the difference — never both
     * independently. That is exactly what allocate() does; this is the bps-shaped face of it. The odd minor
     * unit of an uneven split goes to the side named by $residual, which at volume is real money and so is
     * an explicit choice rather than a consequence of argument order.
     *
     * A fee of 2.5% is 250 bps. `splitByBps(250, RoundingResidual::ToPortion)` returns `[fee, net]` with the
     * leftover unit on the fee; `ToRemainder` puts it on the net. Both are integer math end to end — no float
     * is ever constructed.
     *
     * @return array{self, self} [portion, remainder]
     */
    public function splitByBps(int $bps, RoundingResidual $residual): array
    {
        if ($bps < 0 || $bps > 10_000) {
            throw new InvalidArgumentException("A bps split must be between 0 and 10000; got {$bps}.");
        }

        // allocate() puts the residual on the FIRST ratio. To land it on the portion, the portion's ratio
        // goes first; to land it on the remainder, the remainder's does — then the pair is returned in the
        // fixed [portion, remainder] order regardless, so a caller never has to track which way it ran.
        if ($residual === RoundingResidual::ToPortion) {
            [$portion, $remainder] = $this->allocate($bps, 10_000 - $bps);
        } else {
            [$remainder, $portion] = $this->allocate(10_000 - $bps, $bps);
        }

        return [$portion, $remainder];
    }

    /**
     * Read an amount that INCLUDES a bps markup as the base it was added to and the markup itself.
     *
     * Gross to net: a 119.00 amount that carries 19% (1900 bps) is a 100.00 base plus 19.00. The base is
     * `round(amount / (1 + bps))` as integer minor-unit math, and the markup is the DIFFERENCE — so the two
     * always sum back to the original and a cent is never conjured. At 7% on 119.00 that is 111.21 base and
     * 7.79 markup as the difference, where an independent `base × rate` would have lost a cent.
     *
     * @return array{self, self} [base, markup]
     */
    public function baseFromMarkup(int $bps): array
    {
        if ($bps < 0) {
            throw new InvalidArgumentException("A bps markup cannot be negative; got {$bps}.");
        }

        $base = new self($this->halfUpDiv($this->minorUnits * 10_000, 10_000 + $bps), $this->currency);

        return [$base, $this->minus($base)];
    }

    /**
     * Read an amount that REMAINS after a bps rate was deducted as the base it came from and the deduction.
     *
     * Target payout to net: a 90.00 payout after a 10% (1000 bps) fee reconstructs a 100.00 base, and the
     * deduction is the base minus the amount (10.00). The base is `round(amount / (1 − bps))` in integer
     * minor units; the deduction is the DIFFERENCE, so the base less the deduction is the amount exactly.
     *
     * @return array{self, self} [base, deduction]
     */
    public function baseFromRate(int $bps): array
    {
        if ($bps < 0 || $bps >= 10_000) {
            throw new InvalidArgumentException("A bps rate to reverse must be between 0 and 9999; got {$bps}.");
        }

        $base = new self($this->halfUpDiv($this->minorUnits * 10_000, 10_000 - $bps), $this->currency);

        return [$base, $base->minus($this)];
    }

    /**
     * Integer division rounded half-up by magnitude, no float involved.
     *
     * The magnitude is rounded and the sign reapplied, so a refund (a negative amount) rounds the same
     * distance from zero as the charge it reverses — the alternative (rounding toward negative infinity)
     * would make a reversal off by a cent from the thing it undoes. The divisor is always positive here
     * (10000 ± a bounded bps), so only the dividend carries the sign.
     */
    private function halfUpDiv(int $dividend, int $divisor): int
    {
        $sign = $dividend < 0 ? -1 : 1;
        $magnitude = intdiv(abs($dividend) * 2 + $divisor, $divisor * 2);

        return $sign * $magnitude;
    }

    public function isZero(): bool
    {
        return $this->minorUnits === 0;
    }

    public function isPositive(): bool
    {
        return $this->minorUnits > 0;
    }

    public function isNegative(): bool
    {
        return $this->minorUnits < 0;
    }

    public function equals(self $other): bool
    {
        return $this->minorUnits === $other->minorUnits && $this->currency === $other->currency;
    }

    /** -1 if less than, 0 if equal, 1 if greater — same currency required. */
    public function compareTo(self $other): int
    {
        $this->assertSameCurrency($other);

        return $this->minorUnits <=> $other->minorUnits;
    }

    public function greaterThan(self $other): bool
    {
        return $this->compareTo($other) > 0;
    }

    public function lessThan(self $other): bool
    {
        return $this->compareTo($other) < 0;
    }

    public function greaterThanOrEqual(self $other): bool
    {
        return $this->compareTo($other) >= 0;
    }

    public function lessThanOrEqual(self $other): bool
    {
        return $this->compareTo($other) <= 0;
    }

    /** A plain, locale-independent display string, e.g. "10.00 EUR". Rich locale formatting is an
     *  app/presentation concern, not the value object's. */
    public function format(): string
    {
        return $this->toDecimal().' '.$this->currency;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw CurrencyMismatch::between($this->currency, $other->currency);
        }
    }
}
