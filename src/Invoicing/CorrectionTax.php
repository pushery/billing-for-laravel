<?php

declare(strict_types=1);

namespace Pushery\Billing\Invoicing;

use Pushery\Billing\Models\InvoiceRecord;

/**
 * How much of a correction's gross amount is tax, at the rate of the document it corrects.
 *
 * A refund or a redeemed proration credit reduces a gross amount, and the supply behind it was taxed at the
 * original's rate. So the correction states its own gross as net plus tax at that rate, and the return reduces
 * both the base and the tax of the sale it corrects. Each correction is split on its own, as every document
 * rounds its own tax, and the split is the one whose net, taxed at the stated rate and rounded, gives back the
 * tax: the e-invoice computes its band from the net and the rate, and a split that did not survive that would
 * state a payable amount other than the one credited.
 *
 * The rate is the original's: the statutory rate it records, or for a document from before that column was
 * written, the quotient of its net and tax to one decimal, which is the rate its lines state.
 */
final readonly class CorrectionTax
{
    private function __construct(
        public int $net,
        public int $tax,
        /** The rate the correction's lines state, as a percentage. */
        public float $rate,
    ) {}

    /**
     * The split of a correction of `$gross` against `$original`.
     *
     * Null where the original states no tax at all: nobody determined whether its supply was taxed, and a
     * correction stating a figure for it would be the first document to claim one. No tax where the original
     * carried none, which covers a reverse charge, an exemption and a supply outside the scope of the tax.
     */
    public static function of(InvoiceRecord $original, int $gross): ?self
    {
        $tax = $original->tax_minor;

        if ($tax === null) {
            return null;
        }

        $net = $original->subtotal_minor ?? $original->total_minor - $tax;

        if ($tax === 0 || $net <= 0 || $gross <= 0) {
            return new self($gross, 0, 0.0);
        }

        // In basis points, so the split below stays in integers: 20 % is 2000.
        $bps = $original->supply_rate_bps !== null && $original->supply_rate_bps > 0
            ? $original->supply_rate_bps
            : 10 * (int) round($tax / $net * 1_000);
        $base = intdiv(2 * $gross * 10_000 + 10_000 + $bps, 2 * (10_000 + $bps));

        foreach ([$base, $base - 1, $base + 1] as $candidate) {
            if ($candidate + self::taxOn($candidate, $bps) === $gross) {
                return new self($candidate, $gross - $candidate, $bps / 100);
            }
        }

        // Some gross amounts have no net that reproduces them in whole cents. The nearest net stands, and the tax
        // is the remainder, so the parts still add up to what was credited.
        return new self($base, $gross - $base, $bps / 100);
    }

    /** The tax on a net at a rate in basis points, rounded half up as the e-invoice band rounds it. */
    private static function taxOn(int $net, int $bps): int
    {
        return intdiv(2 * $net * $bps + 10_000, 20_000);
    }
}
