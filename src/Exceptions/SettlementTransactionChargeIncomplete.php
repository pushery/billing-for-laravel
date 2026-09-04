<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use InvalidArgumentException;

/**
 * A settlement transaction named half of a charge's identity.
 *
 * ## Why half is refused rather than tolerated
 *
 * A charge reference is unique only PER PROVIDER — the charge table says so with a composite unique key, and
 * every lookup in the marketplace lane repeats the point. A transaction that named a bare reference could
 * therefore be matched against a charge belonging to a different driver, and the collective document would
 * then record that it settled a sale it never mentioned.
 *
 * That failure is silent in the direction that matters: the settlement itself is arithmetically untouched,
 * the document totals still equal the payout run, and the only thing that is wrong is which row now claims
 * to have been settled by it. Nothing downstream re-checks that claim.
 *
 * ## Why at construction rather than at the run
 *
 * The pair is a property of the transaction, not of the month it lands in. Checking it where it is stated
 * puts the error at the line that wrote it, while checking it in the engine would report a whole month as
 * unsettleable and leave the caller to find which of its transactions was malformed.
 *
 * Naming NEITHER half stays valid, and that is the ordinary case: the link exists only for callers that
 * have a routed charge to point at.
 *
 * Concatenation in this class assembles sentence text rather than behavior, so swapping or dropping
 * a fragment measures where the line was wrapped, not what a test asserts. The values themselves are
 * held by a dedicated guard that varies every parameter individually.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class SettlementTransactionChargeIncomplete extends InvalidArgumentException
{
    public static function make(): self
    {
        return new self(
            'A settlement transaction named half of a charge identity. A charge reference is unique only per '
            .'provider, so a bare reference could attach this transaction to another driver\'s sale — and the '
            .'document would then record that it settled a charge it never mentioned. Pass `chargeProvider` '
            .'and `chargeReference` together, or neither.'
        );
    }
}
