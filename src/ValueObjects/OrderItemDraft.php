<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Pushery\Billing\Enums\OrderItemType;

/**
 * A line an order is about to carry, before any of it is written down.
 *
 * The engine used to create its items straight onto the order as it went, which is why nothing could sit
 * between deciding a line and persisting it. Anything that wants to ADD a line — metered usage an
 * application prices itself, a coupon, a fee — needs a moment where the lines exist and the order does
 * not, because the order's total is the sum of them: a line added after the row is written leaves a
 * total that disagrees with what it totals, and that disagreement is only visible to whoever adds the
 * numbers up by hand.
 *
 * So the cycle is assembled as drafts, handed through the preprocessor chain, and only then written —
 * total included, derived rather than remembered.
 *
 * Amounts are minor units and may be negative: a credit or a discount is a line like any other, and the
 * sum is what makes it reduce the charge. Quantity is separate from the unit price so a metered line can
 * say "1,200 × 0.02 EUR" rather than collapsing to an amount nobody can check.
 */
final readonly class OrderItemDraft
{
    public function __construct(
        public string $description,
        public int $unitPriceMinor,
        public int $quantity,
        public string $currency,
        public OrderItemType $type,
        /** @var array<string, mixed> */
        public array $metadata = [],
    ) {}

    /**
     * What this line contributes to the order.
     *
     * Derived rather than stored, so a draft cannot hold a total that disagrees with its own price and
     * quantity — the one inconsistency a line item can carry that nothing downstream would catch.
     */
    public function totalMinor(): int
    {
        return $this->unitPriceMinor * $this->quantity;
    }

    /** @return array<string, mixed> */
    public function toAttributes(): array
    {
        return [
            'description' => $this->description,
            'unit_price_minor' => $this->unitPriceMinor,
            'quantity' => $this->quantity,
            'total_minor' => $this->totalMinor(),
            'currency' => $this->currency,
            'type' => $this->type,
            'metadata' => $this->metadata,
        ];
    }
}
