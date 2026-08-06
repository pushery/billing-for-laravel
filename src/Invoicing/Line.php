<?php

declare(strict_types=1);

namespace Pushery\Billing\Invoicing;

/**
 * One invoice line, normalized from the loosely-typed JSON stored on the invoice into the fields an
 * EN 16931 line needs: a name, a quantity + unit, the net unit price, the net line amount and the VAT
 * rate. Missing fields degrade to safe defaults (quantity 1, unit "C62" = one piece, zero amounts).
 *
 * The service period is optional and appended, and it is what makes a subscription billable as what it is:
 * each billing cycle is a separately agreed and separately invoiced part of the whole, so each carries its
 * own period. Without one, a subscription invoice does not say what it is for — the reader sees an amount
 * and a date and no answer to "which months". A line with no period renders exactly as it did before, which
 * is what keeps every existing document unchanged.
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
        /** The first day of the period this line covers, as YYYY-MM-DD. */
        public ?string $periodStart = null,
        /** The last day of it. Inclusive: a month billed on the 1st ends on its last day, not the next 1st. */
        public ?string $periodEnd = null,
        /**
         * The cycle this line belongs to, as the billing period names it.
         *
         * Persisted rather than derived, and the two dates are not a substitute for it. A cycle is whatever
         * the subscription says it is — anchored to the signup day, not to the calendar — so two documents
         * can carry the same start and end and belong to different cycles, and one cycle can be re-stated
         * with shifted dates after a plan change. The key is what the usage counter already buckets by, and
         * carrying it here is what lets "one document per period" be an equality rather than a date
         * comparison somebody has to get right twice.
         */
        public ?string $periodKey = null,
    ) {}

    /** Whether this line states the period it covers. */
    public function hasPeriod(): bool
    {
        return $this->periodStart !== null && $this->periodEnd !== null;
    }

    /**
     * One end of a period, kept only when the other end is there too.
     *
     * @param  array<array-key, mixed>  $data
     */
    private static function date(array $data, string $key, mixed $counterpart): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && $value !== '' && is_string($counterpart) && $counterpart !== ''
            ? $value
            : null;
    }

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
            // Both ends or neither: half a period is not a period, and rendering one bound would produce a
            // document claiming a service that started and never finished.
            periodStart: self::date($data, 'period_start', $data['period_end'] ?? null),
            periodEnd: self::date($data, 'period_end', $data['period_start'] ?? null),
            // Kept independently of the dates. A key without dates is still a usable identity for the
            // cycle, and dropping it because a renderer never wrote the bounds would silently remove the
            // only value the idempotency check keys on.
            periodKey: is_string($data['period_key'] ?? null) && $data['period_key'] !== '' ? $data['period_key'] : null,
        );
    }
}
