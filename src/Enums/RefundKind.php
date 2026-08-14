<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * WHY money went back — as a category, not as a sentence.
 *
 * ## Why a free-text reason is not enough
 *
 * A goodwill refund and a statutory withdrawal move the same money in the same direction and are different
 * events in the books. One is a decision the platform made and could have made differently; the other is a
 * right the buyer exercised, and it carries consequences a goodwill refund does not — a value-replacement
 * calculation, a correction on both links of the chain, and a different answer to "why did revenue drop
 * this month".
 *
 * The audit trail already recorded a reason, and a reason is a sentence somebody typed. It cannot be
 * grouped, counted or filtered, and two operators describing the same event write two different strings.
 * The reason stays — it says what happened in this one case — and this says what KIND of thing it was.
 *
 * ## Why `Goodwill` is the default and not `Unspecified`
 *
 * Every refund this package has issued so far was a platform decision: nothing in it exercised a
 * withdrawal right, because nothing could. So the default names what the existing rows actually are rather
 * than admitting it does not know — an `Unspecified` default would make every historical refund look like
 * a question, and there is no question about them.
 *
 * A caller that means something else says so. That is the direction the mistake should point: a withdrawal
 * mislabelled as goodwill understates a buyer's right in one row, where a goodwill refund mislabelled as a
 * withdrawal would claim a right nobody exercised.
 */
enum RefundKind: string
{
    /** The platform decided to give money back. Its own call, and it could have decided otherwise. */
    case Goodwill = 'goodwill';

    /**
     * A consumer exercised a statutory right of withdrawal.
     *
     * Not the platform's decision. What is owed back is decided by the withdrawal profile — including any
     * value replacement for what was already provided — and the correction runs on both links of the
     * chain, not only the buyer's.
     */
    case StatutoryWithdrawal = 'statutory_withdrawal';

    /** Money returned because a supply could not be made, or was defective. */
    case NotDelivered = 'not_delivered';

    /**
     * A buyer fee returned because the sale it mediated was withdrawn.
     *
     * Its own kind rather than a second `StatutoryWithdrawal` row, because it is a different supply between
     * different parties: the withdrawal settles the creator's supply between platform and buyer, and this
     * returns the platform's OWN intermediation supply. Counting them together would report the platform's
     * mediation revenue as part of what a creator earned and gave back.
     *
     * It also moves different money. The mediated sale's refund reverses the creator's transfer in
     * proportion; this one comes entirely out of the platform's share, because that is where the fee went.
     */
    case WithdrawnBuyerFee = 'withdrawn_buyer_fee';

    /**
     * Whether the amount was decided by a rule rather than by whoever pressed the button.
     *
     * The distinction a reader needs before asking "is this figure right?": for a goodwill refund there is
     * nothing to check it against, and for a withdrawal there is.
     */
    public function amountIsDerived(): bool
    {
        return $this === self::StatutoryWithdrawal;
    }
}
