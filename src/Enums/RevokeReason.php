<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * Why a grant was taken back.
 *
 * The reason belongs in its own column rather than in free text, because these are not interchangeable
 * stories about the same event. A statutory WITHDRAWAL is a right the buyer exercised; a REFUND is a
 * commercial decision the platform made; a CHARGEBACK is neither, it was imposed. They are answered to
 * differently by an auditor, and free text cannot be counted.
 */
enum RevokeReason: string
{
    /** The platform gave the money back as a commercial decision. */
    case Refund = 'refund';

    /**
     * The buyer exercised a statutory right of withdrawal.
     *
     * Its own case, not a flavor of Refund. Without the extinguishing flow, EVERY refund inside the
     * statutory window is a CLAIM rather than goodwill — so which of the two happened is the difference
     * between a decision the platform made and one it had no say in.
     */
    case Withdrawal = 'withdrawal';

    /** The provider reversed the payment; nobody on this side chose it. */
    case Chargeback = 'chargeback';

    /** A takedown or a court order. The work is gone for legal reasons, not commercial ones. */
    case DmcaLegal = 'dmca_legal';

    /** The creator's account ended and the work went with it. */
    case CreatorDeleted = 'creator_deleted';

    /** An operator acted by hand. The vaguest reason, and therefore the one that most needs a note beside it. */
    case Admin = 'admin';
}
