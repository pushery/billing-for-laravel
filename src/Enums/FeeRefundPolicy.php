<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * What happens to the platform's own commission when a sale is unwound.
 *
 * Two answers, and which one a platform gives is a commercial decision nobody else can make for it. The
 * package ships the one that keeps the books balanced by default and lets the other be chosen deliberately.
 */
enum FeeRefundPolicy: string
{
    /** The platform gives back its commission in proportion to what was refunded. */
    case Refund = 'refund';

    /**
     * The platform keeps its commission: it performed the handling, and a refunded sale does not undo that.
     *
     * Legitimate where the platform genuinely charged the merchant for a service it rendered. It also means
     * a refund no longer nets to zero across the three parties — the merchant is short the retained fee —
     * so a consumer choosing it must be able to show the merchant why.
     */
    case Retain = 'retain';

    /**
     * Whether the provider should hand the platform's commission back with the refund.
     *
     * Named for the QUESTION rather than the provider's field, because the field is named for the opposite
     * of what a reader expects: Stripe's `refund_application_fee` is `false` when the platform KEEPS the
     * fee. A call site holding the raw boolean is one inverted reading away from a sign error on money, and
     * nothing downstream would notice — the buyer still gets their refund either way.
     */
    public function refundsPlatformFee(): bool
    {
        return $this === self::Refund;
    }

    /**
     * Whether this policy can exist at all under a given supply regime.
     *
     * Retaining a fee presupposes a fee document: something the platform issued to the merchant for a
     * service, which survives the sale being unwound. A commission chain has no such document. There the
     * platform BUYS and RESELLS — its turnover is the margin between two supplies, not a commission — and
     * unwinding the sale unwinds both of them. Money kept afterwards sits on no supply at all: not a
     * retained fee but an unbilled receipt, and one that would appear on a tax return as turnover the
     * platform cannot point at a document for.
     *
     * The regimes are named neutrally on purpose (see SupplyRegime), so this is a structural argument about
     * documents rather than a citation — it holds wherever the two shapes hold.
     */
    public function permittedIn(SupplyRegime $regime): bool
    {
        return match ($this) {
            self::Refund => true,
            self::Retain => $regime !== SupplyRegime::CommissionChain,
        };
    }
}
