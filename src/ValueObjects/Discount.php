<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use InvalidArgumentException;

/**
 * A resolved discount from a coupon code — either a percentage off (an int 1..100) or a fixed amount
 * off (a Money), never both, enforced by the `int|Money` value type. The package owns the discount
 * model; the Stripe driver may map it onto a Stripe coupon, but a discount is not a provider-only
 * concept.
 */
final readonly class Discount
{
    public function __construct(
        public string $code,
        public int|Money $value,
    ) {
        if (is_int($value) && ($value < 1 || $value > 100)) {
            throw new InvalidArgumentException('A percentage discount must be between 1 and 100.');
        }
    }

    public static function percentage(string $code, int $percent): self
    {
        return new self($code, $percent);
    }

    public static function fixed(string $code, Money $amount): self
    {
        return new self($code, $amount);
    }

    /** Apply the discount to an amount, never returning less than zero. */
    public function applyTo(Money $amount): Money
    {
        if (is_int($this->value)) {
            return Money::of(intdiv($amount->minorUnits * (100 - $this->value), 100), $amount->currency);
        }

        $reduced = $amount->minus($this->value);

        return $reduced->isNegative() ? Money::zero($amount->currency) : $reduced;
    }
}
