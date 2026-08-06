<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * Whose supply a routed sale IS — the classification the whole document chain hangs from.
 *
 * Two shapes, and they are not two ways of describing the same transaction. In one the platform buys and
 * resells: there are two supplies, the platform's margin is the difference between them, and the merchant
 * is settled by a document the platform issues. In the other the platform arranges somebody else's sale:
 * there is one supply between two other parties, and the platform's own turnover is only its fee.
 *
 * They produce different documents, different amounts on a tax return, and different parties on a receipt.
 * That is why a sale is classified once and never re-classified: moving a settled transaction between them
 * does not adjust a number, it makes every document already issued about it describe a transaction that
 * did not happen. The only correct path is to cancel both chains and re-issue.
 *
 * The names are neutral. Which supplies fall into which shape, and on what authority, is a jurisdiction's
 * answer and lives in its profile — a consumer elsewhere reads these two words and no statute.
 */
enum SupplyRegime: string
{
    /** The platform buys and resells: two supplies, and the margin between them is its turnover. */
    case CommissionChain = 'commission_chain';

    /** The platform arranges somebody else's supply: its fee is its turnover, and nothing else is. */
    case Intermediation = 'intermediation';

    /**
     * The seller posture this regime requires.
     *
     * They are one decision seen twice — a regime is the document view, a posture is the receipt view — so
     * a pair outside these two is not a configuration to reconcile later but a contradiction: a platform
     * cannot be reselling in its own name on the receipt and merely arranging on the books.
     */
    public function requiredPosture(): SellerOfRecordPosture
    {
        return match ($this) {
            self::CommissionChain => SellerOfRecordPosture::PlatformDeemedSupplier,
            self::Intermediation => SellerOfRecordPosture::PlatformIntermediary,
        };
    }
}
