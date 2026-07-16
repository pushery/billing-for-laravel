<?php

declare(strict_types=1);

namespace Pushery\Billing\Invoicing;

/**
 * One invoice line, normalized from the loosely-typed JSON stored on the invoice into the fields an
 * EN 16931 line needs: a name, a quantity + unit, the net unit price, the net line amount and the VAT
 * rate. Missing fields degrade to safe defaults (quantity 1, unit "C62" = one piece, zero amounts).
 */
final readonly class Line
{
    public function __construct(
        public string $description,
        public string $quantity,
        public string $unit,
        public int $unitPriceMinor,
        public int $netMinor,
        public float $taxRate,
    ) {}

    /** @param array<array-key, mixed> $data */
    public static function fromArray(array $data): self
    {
        $quantity = $data['quantity'] ?? 1;
        $rate = $data['tax_rate'] ?? 0;

        return new self(
            description: is_string($data['description'] ?? null) ? $data['description'] : '',
            quantity: is_int($quantity) || is_float($quantity) ? (string) $quantity : '1',
            unit: is_string($data['unit'] ?? null) ? $data['unit'] : 'C62',
            unitPriceMinor: is_int($data['unit_price_minor'] ?? null) ? $data['unit_price_minor'] : 0,
            netMinor: is_int($data['net_minor'] ?? null) ? $data['net_minor'] : 0,
            taxRate: is_int($rate) || is_float($rate) ? $rate : 0.0,
        );
    }
}
