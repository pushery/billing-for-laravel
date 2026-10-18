<?php

declare(strict_types=1);

namespace Pushery\Billing\Ai;

use InvalidArgumentException;
use Pushery\Billing\ValueObjects\Money;

/**
 * What model calls cost, in millionths of the currency.
 *
 * One call often costs a fraction of a cent, and a spend that rounded each call to cents would count most calls as
 * nothing and never reach a cap. So costs are added up in millionths, and {@see self::toMoney()} rounds once, for a
 * figure a person reads.
 */
final readonly class AiCost
{
    public function __construct(
        public int $micros,
        public string $currency,
    ) {
        if ($micros < 0) {
            throw new InvalidArgumentException('A cost is never negative.');
        }
    }

    public static function zero(string $currency): self
    {
        return new self(0, $currency);
    }

    public function plus(self $other): self
    {
        if ($other->currency !== $this->currency) {
            throw new InvalidArgumentException("A cost in {$other->currency} is not added to one in {$this->currency}.");
        }

        return new self($this->micros + $other->micros, $this->currency);
    }

    /** The cost in the currency's minor units, rounded half up. */
    public function toMoney(): Money
    {
        $divisor = 10 ** max(0, 6 - Money::exponentFor($this->currency));

        return Money::of(intdiv($this->micros + intdiv($divisor, 2), $divisor), $this->currency);
    }
}
