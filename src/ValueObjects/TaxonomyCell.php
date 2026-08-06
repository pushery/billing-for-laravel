<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use LogicException;

/**
 * One cell of the product-classification table, in one of three kinds — and the kind is part of the TYPE
 * rather than a comment beside the value.
 *
 * That distinction is the whole reason this class exists. Two of the nine product shapes cannot be resolved
 * to a fixed answer at all: a voluntary payment takes its answers from whatever it was paid on, and a
 * multi-purpose voucher has no answers yet because nothing has been bought with it. Forcing either into a
 * fixed value produces a defect with no symptom — a voucher taxed at issue instead of at redemption, or a
 * voluntary payment on commissioned work reported as if it were a file download.
 *
 * A caller cannot read a value out of a cell that has none: {@see value()} refuses, so the two cases have
 * to be handled rather than accidentally defaulted.
 */
final readonly class TaxonomyCell
{
    private function __construct(
        private mixed $value,
        private bool $delegated,
        private bool $deferred,
    ) {}

    /** The answer is this, always. */
    public static function fixed(mixed $value): self
    {
        return new self($value, false, false);
    }

    /** The answer belongs to whatever this was sold alongside. */
    public static function delegated(): self
    {
        return new self(null, true, false);
    }

    /** There is no answer yet; one arrives when the thing is actually used. */
    public static function deferred(): self
    {
        return new self(null, false, true);
    }

    public function isDelegated(): bool
    {
        return $this->delegated;
    }

    public function isDeferred(): bool
    {
        return $this->deferred;
    }

    public function isFixed(): bool
    {
        return ! $this->delegated && ! $this->deferred;
    }

    /**
     * The fixed answer.
     *
     * Refuses for the other two kinds rather than returning null, because null at a call site reads as "no
     * opinion" and gets replaced by whatever the caller thinks is reasonable — which is exactly the silent
     * default this class exists to make impossible.
     */
    public function value(): mixed
    {
        if (! $this->isFixed()) {
            throw new LogicException(
                'This classification cell has no fixed value: it is '.($this->delegated ? 'delegated to the '
                .'product it was sold alongside' : 'deferred until the thing is actually used')
                .'. Handle that case rather than reading a value out of it.'
            );
        }

        return $this->value;
    }
}
