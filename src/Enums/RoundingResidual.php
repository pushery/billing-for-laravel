<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * Where the odd minor unit of an uneven split lands.
 *
 * A percentage split of an integer amount rarely divides evenly — one minor unit is left over, and it has
 * to go to exactly one side. Which side is a real decision at volume (it is a cent per uneven transaction,
 * every transaction), so it is named explicitly rather than left to argument order.
 *
 * This is deliberately neutral money vocabulary: the value object knows a "portion" and a "remainder", not
 * a platform or a creator. The mapping from a fee policy (config `billing.marketplace.fee.rounding`, whose
 * default `platform_first` sends the residual to the fee portion) to one of these cases is
 * `fromConfigured()` below, so Money itself carries no tax or marketplace meaning.
 */
enum RoundingResidual: string
{
    /** The leftover minor unit joins the bps PORTION (the first bucket of the split). */
    case ToPortion = 'to_portion';

    /** The leftover minor unit joins the REMAINDER (the second bucket of the split). */
    case ToRemainder = 'to_remainder';

    /**
     * The direction an installation configured, by the name its configuration uses.
     *
     * The mapping lives here rather than at each reader because there are now two of them — the resolver
     * that prices a sale and the corrector that has to reconstruct one — and two copies of a two-way mapping
     * is how they end up disagreeing about which side the odd minor unit went to.
     *
     * Null for anything unrecognized, so a caller decides what to do about a value it cannot honor rather
     * than silently getting one of the two directions.
     */
    public static function fromConfigured(mixed $value): ?self
    {
        return match ($value) {
            'platform_first' => self::ToPortion,
            'creator_first' => self::ToRemainder,
            default => null,
        };
    }

    /**
     * The direction to rebuild a past sale under, when the sale itself never recorded one.
     *
     * The money side of a correction rebuilds the sale from its charge, the document side from its
     * settlement, and their results are subtracted from one another. A row written before the direction was
     * recorded falls back to what the installation does today, and both sides take that fallback from here,
     * so the cent they subtract cannot land on different sides.
     *
     * A value neither direction can honor lands on `ToPortion` rather than throwing. This rebuilds something
     * that already happened, and refusing to answer would make an old charge unreadable over a setting that
     * has nothing to do with it. The resolver that PRICES a sale does throw, because a sale not yet made can
     * still be stopped.
     */
    public static function forReconstruction(mixed $configured): self
    {
        return self::fromConfigured($configured) ?? self::ToPortion;
    }
}
