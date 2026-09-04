<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;

/**
 * A transaction was handed to a settlement run for a period it does not count in.
 *
 * ## Why this is checked at all
 *
 * A caller groups its transactions by period and hands them over. Nothing verified that what arrived belongs
 * to the period being settled, and that silence was affordable only while "supplied in" and "counted in"
 * were always the same date.
 *
 * They are not. Where the buyer's side was taxed on receipt — a term paid up front — the creator's
 * settlement belongs to the month the money arrived, while the service is rendered across the year. A run
 * that grouped by supply date would settle that turnover in a month the buyer's side already taxed
 * elsewhere, and the two legs of one chain would sit in different periods.
 *
 * ## Why that particular drift is worth an exception
 *
 * It opens an input-tax offset across the remaining months, and it does so quietly: every individual
 * document agrees with itself, the totals for each month look ordinary, and the only thing that is wrong is
 * the relationship between two places nobody compares. It surfaces at a reconciliation, long after the
 * documents are issued and numbered.
 *
 * ## Why it refuses instead of reassigning
 *
 * Moving the transaction into the right period here would make the settlement engine a second place the
 * periodisation is decided, and the caller's grouping would silently stop mattering — which is how one
 * quantity ends up with two derivations that nobody sees diverge. The caller states which period a
 * transaction counts in; this only holds it to that statement.
 *
 * Concatenation in this class assembles sentence text rather than behavior, so swapping or dropping
 * a fragment measures where the line was wrapped, not what a test asserts. The values themselves are
 * held by a dedicated guard that varies every parameter individually.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class SettlementTransactionOutsidePeriod extends RuntimeException
{
    public static function make(string $settling, string $counted): self
    {
        return new self(
            "A settlement run for period [{$settling}] was given a transaction that counts in [{$counted}]. "
            .'Both legs of a chain must land in the same period: where the buyer\'s side was taxed on receipt, '
            .'the creator\'s settlement belongs to the month the money arrived rather than to each month as it '
            .'is rendered. Group the transaction into the period it counts in — set `countsIn` where that '
            .'differs from the supply date — rather than settling it here.'
        );
    }
}
