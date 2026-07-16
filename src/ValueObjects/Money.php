<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use InvalidArgumentException;
use Pushery\Billing\Exceptions\CurrencyMismatch;

/**
 * A monetary amount as integer minor units plus an ISO-4217 currency, normalized across every
 * payment driver. Stripe and Adyen speak integer minor units; Mollie speaks a decimal-string
 * amount + currency — Money is the single representation that crosses the contract boundary, so a
 * raw int or string never does. All arithmetic is integer/string based; floats are never used.
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
     * Build from a decimal-string amount (the Mollie representation), e.g. "10.00" EUR → 1000
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
